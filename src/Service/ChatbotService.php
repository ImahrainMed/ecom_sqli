<?php

namespace App\Service;

use App\Entity\Product;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs entirely on a local Ollama instance (llama3.2), including function
 * calling (search_products, list_categories, get_cart_contents, add_to_cart,
 * etc. — see buildRequestPayload()'s `tools` and resolveResponse()'s
 * dispatch): confirmed in real conditions that llama3.2 returns proper
 * `tool_calls` via Ollama's /api/chat endpoint, unlike gemma3:4b (see
 * DashboardInsightService, which still defaults to Gemini for its
 * tool-free single-turn summaries and only uses gemma3:4b via Ollama as an
 * opt-in AI_PROVIDER switch).
 *
 * There is deliberately no provider switch here: Ollama is the only backend,
 * with no Gemini code path left to fall back to.
 *
 * Known limitation of llama3.2 vs Gemini, confirmed reproducible over
 * several manual trials: the tool results this class sends back (see
 * respondWithFunctionResult()) are always correct — verified independently
 * against the real session/cart state — but the model's own natural-language
 * paraphrase of a get_cart_contents/get_cart_total result is unreliable on
 * the actual numbers (e.g. reporting a quantity of 1 for a cart that really
 * has 2, or doubling the total). Each trial got at most one of
 * quantity/total right, never consistently both. This is a paraphrasing
 * accuracy issue in the model's response text, not a bug in the tool-calling
 * plumbing itself (the structured tool_calls arguments going the other way,
 * e.g. add_to_cart's product_name/quantity, have been reliable in testing).
 * Worth re-evaluating if a more capable local model becomes available.
 *
 * Second known limitation, distinct from the one above: even after the
 * category/max_price/sort normalization fixes in handleSearchProducts()
 * (see normalizeCategoryArg()/normalizeMaxPriceArg()/normalizeSortArg()),
 * llama3.2 occasionally sends a search_products call whose arguments are
 * internally inconsistent with each other for the same user request — e.g.
 * dropping category entirely on one trial of the same phrasing that
 * included it on another, or pairing "pas cher"/"cheapest"-style intent
 * with both an unrelated sort:"most_expensive" AND a max_price far below
 * any real product's price. Confirmed via raw tool_calls logging: each
 * individual argument was valid and handled correctly by the dispatch code
 * (a genuinely empty category, an out-of-range max_price, and a real sort
 * value all normalize and execute exactly as designed), the fetched result
 * was simply empty because that particular combination of filters legitimately
 * matches nothing — there was no dispatch bug to fix. This is a ceiling on
 * the 3B model's argument-consistency, not a defect in this class's request
 * handling; retrying the same phrasing was observed to sometimes succeed
 * and sometimes fail from arguments alone. Worth re-evaluating alongside the
 * paraphrasing issue above if a more capable local model becomes available.
 */
class ChatbotService
{
    private const API_URL = 'http://host.docker.internal:11434/api/chat';
    private const MODEL = 'llama3.2';

    // Measured on this system prompt + full 8-tool schema (~2.7k prompt
    // tokens): a cold call (nothing cached yet) takes ~175s, almost all of
    // it prompt evaluation, not model loading — Ollama then caches that
    // prompt prefix, so a follow-up call with the same/similar prefix drops
    // to ~10-15s. There is no quota/backoff concept for a local model, so
    // this is a single generous attempt rather than a retry loop: retrying
    // an aborted request wouldn't reliably be any faster, since it's not
    // clear the server-side cache still gets populated once the client has
    // already disconnected.
    private const OLLAMA_TIMEOUT_SECONDS = 200;

    // Ollama defaults to unloading an idle model after 5 minutes (confirmed
    // via GET /api/ps), which also throws away whatever prompt-prefix cache
    // is what makes a warm follow-up call (~10-15s) so much faster than a
    // cold one (~175s, see OLLAMA_TIMEOUT_SECONDS) — a realistic pause
    // between chat messages can easily exceed 5 minutes. 30 minutes keeps
    // the model (and that cache) resident across a normal chat session
    // without holding memory indefinitely for a session that's actually
    // been abandoned.
    private const OLLAMA_KEEP_ALIVE = '30m';

    // Fixed, backend-authored replies for get_page_link — see
    // handleGetPageLink() for why this deliberately skips the model.
    private const PAGE_LINK_MESSAGES = [
        'home' => "Voici le lien vers la page d'accueil :",
        'cart' => 'Voici le lien vers votre panier :',
        'dashboard' => 'Voici le lien vers le tableau de bord :',
    ];

    private const SYSTEM_INSTRUCTION = <<<'TEXT'
        Tu es l'assistant d'achat de la boutique en ligne Ecom SQLI, un site e-commerce
        généraliste dont le catalogue est réparti en plusieurs catégories de produits
        (informatique, vêtements, maison, livres, etc.), avec des prix allant approximativement
        de 10 à 320 MAD. Détecte automatiquement la langue du message de l'utilisateur et
        réponds toujours dans cette même langue (français si l'utilisateur écrit en français,
        anglais s'il écrit en anglais, etc.) — ne fixe jamais la langue de réponse à l'avance.
        Reste concis. Le contexte de la conversation (messages précédents) t'est fourni à
        chaque appel : utilise-le pour comprendre les questions de suivi (ex: "et en moins
        cher ?" fait référence aux produits mentionnés juste avant).

        RÈGLE IMPORTANTE — distingue toujours deux types de messages avant de décider quoi faire :
        1. L'utilisateur demande COMMENT faire quelque chose, ou si une fonctionnalité existe (ex:
           "comment je filtre par prix ?", "est-ce qu'il y a un filtre de prix ?", "comment je
           cherche un produit ?") : c'est une question sur le fonctionnement du site. Réponds
           UNIQUEMENT en texte, à partir des informations ci-dessous. N'appelle JAMAIS une
           fonction pour ce type de message, même si la réponse mentionne qu'une fonction existe.
        2. L'utilisateur VEUT DÉJÀ un résultat concret (ex: "montre-moi des produits à moins de
           100 MAD", "cherche des écouteurs", "ajoute ce produit à mon panier") : c'est une
           demande d'action réelle. Appelle la fonction correspondante.
        En cas de doute, une question qui commence par "comment", "est-ce que", "peut-on" ou
        équivalent est presque toujours le cas 1 (FAQ), jamais le cas 2 (action).

        Tu peux répondre directement, sans appeler aucune fonction, à des questions sur le
        fonctionnement du site lui-même (ce sont des informations statiques, pas besoin de les
        vérifier) :
        - Filtrer par catégorie : sur la page d'accueil, cliquer un lien de catégorie dans la
          barre de navigation ajoute ?category=nom-de-la-categorie à l'URL (ex: /?category=electronics).
        - Filtrer par prix : il n'existe PAS de filtre de prix dans l'interface du site. Si
          l'utilisateur demande COMMENT filtrer par prix (cas 1 ci-dessus), réponds simplement en
          texte qu'un tel filtre n'existe pas dans l'interface, mais qu'il peut te demander
          directement des produits avec un budget maximum ici même dans ce chat — NE PAS appeler
          search_products dans ce cas, il n'a encore rien demandé de précis.
          Exemple d'ACTION distinct (cas 2, PAS une réponse à donner à une question "comment") :
          si l'utilisateur écrit quelque chose comme "montre-moi des produits à moins de 100 MAD",
          c'est lui-même en train de faire une vraie recherche par prix — c'est seulement dans ce
          cas-là qu'il faut appeler search_products avec max_price rempli.
        - Rechercher un produit : la barre de recherche en haut de la page d'accueil (paramètre
          q dans l'URL, ex: /?q=casque) filtre le catalogue par mot-clé.
        - Utiliser le panier : on peut ajouter un produit au panier depuis sa fiche produit ou
          la page d'accueil (bouton "Add to cart"), puis sur la page panier (/cart) changer la
          quantité (champ numérique qui s'applique automatiquement dès qu'on le modifie) ou
          retirer un article (bouton "Remove"). Tout cela est aussi possible directement dans ce
          chat via les fonctions add_to_cart, update_cart_item et remove_from_cart décrites plus
          bas.
        - Consulter une fiche produit : cliquer sur un produit dans la liste de la page
          d'accueil, ou sur une carte produit que tu affiches toi-même dans le chat.

        Quand l'utilisateur demande explicitement à être emmené vers une page du site (ex:
        "amène-moi au panier", "je veux voir le dashboard", "montre-moi l'accueil"), appelle la
        fonction get_page_link avec la page correspondante au lieu de deviner ou d'inventer une
        URL toi-même.

        Quand l'utilisateur cherche, compare ou demande des produits (par catégorie, prix ou
        mot-clé), appelle la fonction search_products au lieu d'inventer des résultats. Si la
        demande est trop vague pour lancer une recherche utile (aucun mot-clé, catégorie ni
        budget identifiable, ex: "je cherche un truc"), pose plutôt une question de
        clarification courte (budget, catégorie, usage) avant d'appeler la fonction, au lieu de
        l'appeler avec des paramètres quasi vides ou de répondre qu'il n'y a aucun résultat.

        Si un mot-clé risque d'être trop strict (faute de frappe probable, orthographe
        incertaine, terme peu courant), tu peux appeler search_products plusieurs fois dans la
        même réponse avec des variantes ou synonymes (ex: "ecouteur" → aussi "casque", "audio")
        pour maximiser les chances de trouver des résultats pertinents ; les résultats des
        différents appels seront fusionnés automatiquement.

        Quand l'utilisateur exprime une contrainte de prix vague ("pas cher", "petit budget",
        "cheap", etc.) sans montant précis, estime un max_price raisonnable correspondant à la
        partie basse de la fourchette de prix du catalogue plutôt que de laisser ce paramètre
        vide.

        Ne confonds pas cette contrainte de budget vague avec une demande de tri : "le moins
        cher"/"the cheapest"/"la moins chère" (et à l'inverse "le plus cher"/"the most expensive")
        ne sont PAS une contrainte de prix mais une demande de classement — l'utilisateur veut LE
        produit le moins cher du catalogue (ou du sous-ensemble filtré), pas un produit sous un
        certain montant. Pour ce cas, utilise le paramètre sort de search_products ("cheapest" ou
        "most_expensive") au lieu de deviner un max_price : deviner un max_price ici est risqué
        car un seuil trop bas exclut silencieusement tous les produits réels et fait répondre à
        tort qu'aucun résultat n'a été trouvé, alors que le produit le moins cher du catalogue
        existe bel et bien.

        Quand l'utilisateur demande quelles catégories sont disponibles, appelle la fonction
        list_categories pour obtenir la liste exacte et à jour au lieu de l'inventer. Pour les
        autres questions, réponds directement en texte.

        Les catégories sont enregistrées en anglais dans la base de données : Electronics,
        Clothing, Home & Kitchen, Books. Quand tu renseignes le paramètre category de
        search_products, utilise TOUJOURS le nom anglais exact tel qu'il existe en base, même
        si l'utilisateur s'exprime dans une autre langue — par exemple "vêtements", "habits" ou
        "clothes" → "Clothing" ; "électronique", "informatique" ou "electronics" →
        "Electronics" ; "maison", "cuisine" ou "kitchen" → "Home & Kitchen" ; "livres" ou
        "books" → "Books". En cas de doute sur le nom exact d'une catégorie, appelle
        list_categories pour vérifier plutôt que de deviner.

        Tu peux aussi consulter et modifier le panier de l'utilisateur via des fonctions
        dédiées. Pour le consulter (lecture seule, aucune confirmation nécessaire), appelle
        get_cart_contents pour connaître son contenu détaillé (articles, quantités,
        sous-totaux) ou get_cart_total pour connaître son montant total, puis formule
        toi-même la réponse en langage naturel à partir du résultat, en reprenant
        exactement le nom et la quantité renvoyés par la fonction — jamais un nom de
        produit que tu inventerais ou emprunterais d'ailleurs (ex: pour un article nommé
        [nom du produit] avec une quantité de 2 : "vous avez 2x [nom du produit] dans
        votre panier").

        Pour le modifier, utilise add_to_cart (ajouter une quantité d'un produit),
        update_cart_item (changer la quantité d'un produit déjà dans le panier) ou
        remove_from_cart (retirer un produit). Ces trois fonctions cherchent le produit par
        son nom : si le résultat indique "not_found", dis à l'utilisateur que tu n'as pas
        trouvé ce produit et demande-lui de préciser ; s'il indique "ambiguous", liste les
        produits candidats renvoyés et demande à l'utilisateur lequel il veut ; s'il indique
        "error" (ex: stock insuffisant), explique-le clairement en te basant sur le stock
        disponible fourni.

        CONFIRMATION AVANT MODIFICATION DU PANIER : pour add_to_cart, update_cart_item et
        remove_from_cart, l'exécution réelle est entièrement gérée par le backend, pas par toi.
        Le premier appel que tu fais à l'une de ces fonctions ne modifie jamais le panier : il
        renvoie toujours "status": "pending_confirmation", quoi que tu aies dit à
        l'utilisateur avant d'appeler la fonction. Quand tu reçois "pending_confirmation",
        formule une question de confirmation claire en reprenant exactement le nom et la
        quantité renvoyés par la fonction — jamais un nom de produit que tu inventerais ou
        emprunterais d'ailleurs (ex: pour un article nommé [nom du produit] et une quantité
        de 2 : "Vous voulez que j'ajoute 2x [nom du produit] à votre panier, c'est bien
        ça ?") et ne dis JAMAIS que l'action est faite à ce stade, même si tu penses qu'elle
        l'est. Si l'utilisateur confirme ensuite
        clairement (ex: "oui", "confirme", "vas-y"), le backend exécute l'action tout seul et
        te renvoie un nouveau résultat de fonction avec "status": "success" (ou une erreur) pour
        cet appel précis ; c'est uniquement à ce moment-là que tu peux dire que c'est fait. Tu
        n'as donc jamais besoin d'appeler toi-même une deuxième fois add_to_cart,
        update_cart_item ou remove_from_cart pour confirmer une action déjà proposée. Cette
        règle ne s'applique jamais à get_cart_contents ou get_cart_total : ces deux fonctions
        de lecture seule peuvent être appelées immédiatement, sans confirmation.
        TEXT;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ProductRepository $productRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly ChatHistoryService $chatHistory,
        private readonly CartService $cartService,
        private readonly ChatPendingActionService $pendingAction,
        private readonly ChatLastSearchService $lastSearch,
    ) {
    }

    /**
     * @return array{type: 'text', message: string, cartUpdated?: true, link?: string}|array{type: 'products', message: string, products: list<array{name: string, price: float, in_stock: bool, url: string}>}|array{type: 'cart', message: string, items: list<array{name: string, quantity: int, subtotal: float}>, total: float}
     */
    public function ask(string $userMessage): array
    {
        $this->chatHistory->addUserMessage($userMessage);

        $pending = $this->pendingAction->get();

        if (null !== $pending) {
            // Whatever the user says next resolves the pending action one way
            // or another: a recognized confirmation executes it, an explicit
            // decline cancels it right here, and anything else (a new
            // question, a change of subject) also cancels it but falls
            // through to be processed as a normal message. Either way it
            // must not survive past this message.
            $this->pendingAction->clear();

            if ('remove_from_cart_quantity_choice' === $pending['function']) {
                // Distinct from the generic yes/no confirmation below: this
                // reply can be "remove everything" OR a specific quantity —
                // see handleRemoveFromCart()/resolveRemoveQuantityChoice().
                $response = $this->resolveRemoveQuantityChoice($userMessage, $pending['args']);

                if (null !== $response) {
                    $this->chatHistory->addBotMessage($response);

                    return $response;
                }
                // Unrecognized reply: falls through and gets processed as a
                // normal new message below, same as a declined confirmation
                // does for the other pending action types.
            } elseif ($this->looksLikeConfirmation($userMessage)) {
                $response = $this->executePendingAction($pending);
                $this->chatHistory->addBotMessage($response);

                return $response;
            } elseif ($this->looksLikeDecline($userMessage)) {
                // Answered here, without ever going back to Ollama — see
                // looksLikeDecline()'s docblock for the repeat-question loop
                // this closes: falling through to the model with the
                // just-asked confirmation still in its context risked it
                // re-deciding to call the same cart-mutating function again.
                $response = ['type' => 'text', 'message' => "D'accord, j'annule cette action."];
                $this->chatHistory->addBotMessage($response);

                return $response;
            }
        }

        $faqAnswer = $this->detectFaqAnswer($userMessage);

        if (null !== $faqAnswer) {
            $response = ['type' => 'text', 'message' => $faqAnswer];
            $this->chatHistory->addBotMessage($response);

            return $response;
        }

        if ($this->detectCartCountQuestion($userMessage)) {
            $response = $this->handleGetCartContents();
            $this->chatHistory->addBotMessage($response);

            return $response;
        }

        $directCartAction = $this->detectDirectCartAction($userMessage);

        if (null !== $directCartAction) {
            $response = match ($directCartAction['function']) {
                'add_to_cart' => $this->handleAddToCart($directCartAction['args']),
                'remove_from_cart' => $this->handleRemoveFromCart($directCartAction['args']),
                'update_cart_item' => $this->handleUpdateCartItem($directCartAction['args']),
            };
            $this->chatHistory->addBotMessage($response);

            return $response;
        }

        $response = $this->resolveResponse($userMessage);

        $this->chatHistory->addBotMessage($response);

        return $response;
    }

    // Trailing phrases stripped off the end of a detected product-name
    // fragment in detectDirectCartAction() — e.g. "Nobis Neque Itaque à mon
    // panier" → "Nobis Neque Itaque". Longest-first so "de mon panier"
    // doesn't get partially matched by a shorter overlapping entry first.
    private const CART_TRAILING_PHRASES = [
        'dans mon panier', 'de mon panier', 'à mon panier', 'a mon panier',
        'du panier', 'au panier',
    ];

    /**
     * Detects a short, direct cart-action command ("add X", "ajoute X à mon
     * panier", "retire X", "change la quantité de X à N") — an action verb
     * as the very first word, followed by what looks like a product
     * reference (and a quantity, where relevant) — and if it parses
     * cleanly, returns the target function name and its args ready to hand
     * straight to the matching handleXxx() method.
     *
     * Exists because llama3.2 was observed reproducibly (3/3 trials) calling
     * search_products instead of add_to_cart for terse commands like "add
     * Nobis Neque Itaque" — a tool-selection failure. Tried steering Ollama
     * around it first (a per-message system note, and separately restricting
     * the `tools` array to just the target function) — both made things
     * worse: with a mismatched `tools` array the model started hallucinating
     * freeform JSON naming functions that were not even offered, and a
     * system message appended after the final user turn broke its chat
     * template badly enough to leak raw formatting tokens into the reply.
     * Bypassing Ollama entirely for this narrow, well-defined class of
     * message — same principle as detectFaqAnswer() — sidesteps all of that:
     * handleAddToCart()/handleRemoveFromCart()/handleUpdateCartItem()
     * already do the real product resolution (via findProductByName()) and
     * build the fixed confirmation message themselves, so this only needs
     * to extract the args, not talk to the model at all.
     *
     * Deliberately narrow: only matches when the verb is the very first
     * word, so "je cherche à ajouter de la variété à ma commande" — where
     * "ajouter" is buried mid-sentence, not a command — doesn't match. A
     * false negative here is safe (falls through to the normal Ollama
     * flow); a false positive would incorrectly hijack a real search, so
     * this stays conservative on purpose — including giving up and
     * returning null (rather than guessing) whenever the remaining text
     * looks empty, or (for update_cart_item) whenever no explicit quantity
     * number is found.
     *
     * @return array{function: string, args: array<string, mixed>}|null
     */
    private function detectDirectCartAction(string $message): ?array
    {
        $normalized = mb_strtolower(trim($message));

        if (preg_match('/^(?:change|changer|modifie)\b\s+(?:la\s+)?quantit[ée]\s+de\s+(.+?)\s+(?:à|a|pour)\s+(\d+)\b/u', $normalized, $matches)) {
            $productName = trim($matches[1]);

            return '' === $productName ? null : [
                'function' => 'update_cart_item',
                'args' => ['product_name' => $productName, 'new_quantity' => (int) $matches[2]],
            ];
        }

        static $addVerbs = ['add', 'ajoute', 'ajouter'];
        static $removeVerbs = ['retire', 'retirer', 'supprime', 'supprimer', 'remove'];

        if (preg_match($this->cartVerbPattern($addVerbs), $normalized, $matches)) {
            $remainder = $this->stripCartTrailingPhrase($matches[1]);
            $quantity = 1;

            if (preg_match('/^(\d+)\s+(\S.*)$/u', $remainder, $quantityMatch)) {
                $quantity = (int) $quantityMatch[1];
                $remainder = $quantityMatch[2];
            }

            $productName = trim($remainder);

            return '' === $productName ? null : [
                'function' => 'add_to_cart',
                'args' => ['product_name' => $productName, 'quantity' => $quantity],
            ];
        }

        if (preg_match($this->cartVerbPattern($removeVerbs), $normalized, $matches)) {
            $productName = trim($this->stripCartTrailingPhrase($matches[1]));

            return '' === $productName ? null : [
                'function' => 'remove_from_cart',
                'args' => ['product_name' => $productName],
            ];
        }

        return null;
    }

    /**
     * @param list<string> $verbs
     */
    private function cartVerbPattern(array $verbs): string
    {
        $alternation = implode('|', array_map(
            static fn (string $verb) => preg_quote($verb, '/'),
            $verbs,
        ));

        return '/^(?:' . $alternation . ')\b\s+(\S.*)$/u';
    }

    private function stripCartTrailingPhrase(string $text): string
    {
        foreach (self::CART_TRAILING_PHRASES as $phrase) {
            if (str_ends_with($text, ' ' . $phrase)) {
                return substr($text, 0, -\strlen($phrase) - 1);
            }
        }

        return $text;
    }

    /**
     * Structural pre-filter for FAQ-shaped questions about how the site
     * works, checked BEFORE ever calling Ollama — exists because prompt-only
     * guidance for this exact "comment X" (how does X work) vs "je veux X"
     * (do X now) distinction was tried and failed reproducibly: llama3.2
     * kept calling search_products regardless of how explicitly the system
     * prompt told it not to (confirmed over 7/7 trials). This sidesteps the
     * model's tool-selection behavior entirely for this narrow class of
     * message instead of trying to word the prompt around it again.
     *
     * Deliberately conservative — requires both an interrogative marker
     * ("comment", "est-ce que", "peut-on", "où"/"ou") AND a site-
     * functionality keyword, and backs off the moment a digit is present: a
     * quantity is a strong, cheap signal that the user wants a real action
     * performed right now (e.g. "comment ajouter 2 écouteurs à mon panier"),
     * not an explanation of how a feature works. A false negative here just
     * means the message falls through to the normal Ollama flow, so
     * under-triggering is the safe failure mode — this never tries to
     * intercept anything it isn't confident about.
     *
     * Returns the fixed FAQ text to answer with, or null if this message
     * doesn't look like a pure "how does X work" question.
     */
    private function detectFaqAnswer(string $message): ?string
    {
        $normalized = mb_strtolower(trim($message));

        // A quantity is the clearest signal available before ever touching
        // the catalog that this is a real action request, not a FAQ one.
        if ('' === $normalized || preg_match('/\d/', $normalized)) {
            return null;
        }

        static $interrogativeMarkers = [
            'comment', 'est-ce que', 'est ce que', 'peut-on', 'peut on',
            'où', 'ou est', 'ou se trouve', 'ou peut',
        ];

        $hasInterrogative = false;
        foreach ($interrogativeMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                $hasInterrogative = true;
                break;
            }
        }

        if (!$hasInterrogative) {
            return null;
        }

        // Checked in order, first match wins — deliberately mirrors the
        // FAQ bullet list in SYSTEM_INSTRUCTION, so this stays a structural
        // shortcut to the same information rather than a divergent source
        // of truth.
        static $topics = [
            [
                'keywords' => ['prix', 'filtre', 'filtrer'],
                'answer' => "Il n'existe pas de filtre de prix dans l'interface du site : vous pouvez me demander directement ici des produits avec un budget maximum (ex: \"montre-moi des produits à moins de 100 MAD\").",
            ],
            [
                'keywords' => ['categorie', 'catégorie'],
                'answer' => "Sur la page d'accueil, cliquez un lien de catégorie dans la barre de navigation pour filtrer le catalogue par catégorie.",
            ],
            [
                'keywords' => ['rechercher', 'recherche', 'chercher'],
                'answer' => "La barre de recherche en haut de la page d'accueil filtre le catalogue par mot-clé. Vous pouvez aussi me demander directement ici de chercher un produit.",
            ],
            [
                'keywords' => ['commande'],
                'answer' => "Le suivi des commandes passées n'est pas encore disponible sur le site pour le moment.",
            ],
            [
                'keywords' => ['panier', 'ajouter'],
                'answer' => "Vous pouvez ajouter un produit au panier depuis sa fiche produit ou la page d'accueil (bouton \"Add to cart\"), puis sur la page panier (/cart) changer la quantité ou retirer un article. Tout cela est aussi possible directement ici dans le chat — dites-moi quel produit et en quelle quantité.",
            ],
            [
                'keywords' => ['fiche produit', 'produit'],
                'answer' => "Cliquez sur un produit dans la liste de la page d'accueil, ou sur une carte produit que je vous affiche ici, pour voir sa fiche complète.",
            ],
        ];

        foreach ($topics as $topic) {
            foreach ($topic['keywords'] as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $topic['answer'];
                }
            }
        }

        return null;
    }

    /**
     * Structural pre-filter for a "how many products/items do I have (in my
     * cart / there)" question, checked BEFORE ever calling Ollama — same
     * bypass principle as detectFaqAnswer()/detectDirectCartAction(): this
     * routes straight to handleGetCartContents(), a read-only function that
     * needs no confirmation, so short-circuiting Ollama's discretion here
     * costs nothing.
     *
     * Motivated by a real observed case — "how many product i have there"
     * got answered with generic catalog text instead of the cart's actual
     * contents — though retesting that exact phrasing afterward reproduced
     * the correct cart answer 9/9 times across several conversation
     * contexts (fresh session, right after a cart mutation, after browsing
     * the catalog, with multiple cart items), so this looks like rare
     * model-sampling variance rather than the same complete, deterministic
     * gap detectDirectCartAction() fixes for add_to_cart. Added anyway as a
     * zero-cost safety net for this specific phrasing.
     *
     * Deliberately requires both a counting word ("combien"/"how many"/"how
     * much") AND a product/cart-referring word, so an unrelated sentence
     * that happens to contain one alone doesn't get misrouted (e.g. "combien
     * coûte ce produit ?" is a price question about one product, not a cart
     * count, hence the price-word exclusion below).
     */
    private function detectCartCountQuestion(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        $normalized = preg_replace('/[^\p{L}\p{N}\s\'-]/u', '', $normalized) ?? $normalized;

        if (preg_match('/\bco[uû]te\b|\bprix\b|\bcost\b|\bprice\b/u', $normalized)) {
            return false;
        }

        if (!preg_match('/\bcombien\b|\bhow many\b|\bhow much\b/u', $normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/\bproduits?\b|\barticles?\b|\bproducts?\b|\bitems?\b|\bpanier\b|\bcart\b|\bthere\b/u',
            $normalized,
        );
    }

    // First-word keyword lists for looksLikeConfirmation()/looksLikeDecline().
    // Kept as class consts (rather than the previous per-method `static`
    // locals) since matchesKeywordFuzzy() now needs both from one place.
    private const CONFIRMATION_KEYWORDS = [
        'oui', 'ouais', 'ouep', 'yes', 'yep', 'yeah', 'yup',
        'confirme', 'confirmé', 'confirmer', 'confirmed',
        'vas-y', 'vasy', 'go',
        'ok', 'okay', "d'accord", 'daccord', 'dac',
    ];

    private const DECLINE_KEYWORDS = [
        'non', 'no', 'nope', 'nan', 'nah',
        'annule', 'annuler', 'annulé', 'annulée', 'cancel',
        'stop', 'jamais', 'négatif', 'negatif',
    ];

    /**
     * Recognizes a confirmation reply — an exact match against
     * CONFIRMATION_KEYWORDS, or (see matchesKeywordFuzzy()) a close typo of
     * one, e.g. "OOUI", "confirmr". Deliberately narrow beyond that: anything
     * not clearly a "yes" is treated as a decline/topic change (see the
     * pending-action handling in ask()), so a false negative here just means
     * the user is asked to re-issue the action, while a false positive would
     * execute a cart mutation the user didn't actually confirm.
     *
     * Bug fixed here (observed in real testing): a single-letter-off typo
     * like "OOUI" wasn't recognized on the first try because the previous
     * version only ever did an exact match against the keyword list — no
     * amount of retrying with cleaner spelling would have helped a user who
     * didn't realize *why* it failed. Typo tolerance below fixes that
     * structurally instead of asking the user to type more carefully.
     */
    private function looksLikeConfirmation(string $message): bool
    {
        return $this->matchesKeywordFuzzy($message, self::CONFIRMATION_KEYWORDS);
    }

    /**
     * Recognizes an explicit decline/cancellation reply ("non", "no",
     * "annule", "cancel"...), with the same typo tolerance as
     * looksLikeConfirmation(). Used by ask() to intercept a decline and
     * cancel the pending action immediately with a fixed message, instead
     * of letting it fall through to Ollama.
     *
     * Bug fixed here (observed in real testing): previously, any reply that
     * wasn't a recognized confirmation — including an explicit "non" — just
     * cleared the pending action and fell through to the normal Ollama
     * flow. But that flow still carries the just-asked confirmation
     * question in conversation history, and the model would sometimes
     * re-decide to call the very same add_to_cart/update_cart_item/
     * remove_from_cart tool again from that context, producing a fresh
     * "pending_confirmation" and the identical question — an infinite loop
     * from the user's perspective no matter how many times they said "non".
     * Recognizing "non" explicitly and answering with a fixed
     * backend-authored cancellation message, without ever going back to
     * Ollama for it, closes that loop structurally.
     */
    private function looksLikeDecline(string $message): bool
    {
        return $this->matchesKeywordFuzzy($message, self::DECLINE_KEYWORDS);
    }

    /**
     * Shared matcher behind looksLikeConfirmation()/looksLikeDecline():
     * normalizes case/punctuation, then accepts either an exact first-word
     * match against $keywords or one within a small Levenshtein distance of
     * one of them, so simple typos/repeated letters/case mistakes ("OOUI",
     * "Ouiii", "NON") are still recognized without a second attempt from the
     * user. The distance threshold scales with keyword length (0 — exact
     * match only — for very short 2-letter words like "ok"/"go"/"no", 1 for
     * 3-4 letter words like "oui"/"non"/"stop", 2 for longer ones like
     * "confirme"), so a short word doesn't fuzzy-match something unrelated
     * just because "everything is 1 edit away from a 2-letter string".
     *
     * Bug fixed here (found while testing): an earlier version used the same
     * distance-1 threshold down to 2-letter keywords, which made "no" (a
     * DECLINE keyword) match "go" (a CONFIRMATION keyword, distance 1) —
     * i.e. an explicit decline was getting executed as if it were a
     * confirmation. Exact-match-only for 2-letter keywords, verified via a
     * full pairwise collision check between CONFIRMATION_KEYWORDS and
     * DECLINE_KEYWORDS, closes that specific case and prevents the class of
     * bug in general.
     *
     * @param list<string> $keywords
     */
    private function matchesKeywordFuzzy(string $message, array $keywords): bool
    {
        $normalized = mb_strtolower(trim($message));
        $normalized = preg_replace('/[^\p{L}\p{N}\s\'-]/u', '', $normalized) ?? $normalized;
        $firstWord = preg_split('/\s+/', $normalized)[0] ?? '';

        if ('' === $firstWord) {
            return false;
        }

        if (in_array($firstWord, $keywords, true)) {
            return true;
        }

        foreach ($keywords as $keyword) {
            $keywordLength = mb_strlen($keyword);
            $maxDistance = match (true) {
                $keywordLength <= 2 => 0,
                $keywordLength <= 4 => 1,
                default => 2,
            };

            if ($maxDistance > 0 && levenshtein($firstWord, $keyword) <= $maxDistance) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detects that the user's message explicitly asked for a quantity of 0
     * ("mets la quantité de X à 0", "je veux 0 X dans mon panier", "set X to
     * 0") rather than using a genuine removal verb — used by
     * resolveResponse() to reroute a remove_from_cart tool call to
     * update_cart_item(new_quantity: 0) so the reply stays worded the way
     * the user actually asked (see the call site's comment). Deliberately
     * requires nothing more than a bare "0"/"zéro" for this, on top of the
     * removal-verb exclusion below: this is only ever consulted once the
     * model has already chosen to call remove_from_cart, so within that
     * already-narrow context a bare 0/zéro is a strong, standalone signal
     * that the user meant a quantity, even without also using a word like
     * "quantité" or "mets" (confirmed needed: "je veux 0 X dans mon panier"
     * mentions neither, and requiring one wrongly let it fall through to
     * remove_from_cart's ambiguous-quantity flow instead of this reroute).
     * Backs off (returns false) the moment a real removal verb ("retire",
     * "supprime", etc.) also appears in the message, since that's a
     * clearer, more direct signal that the user meant an actual removal and
     * remove_from_cart's own wording is already correct for that case.
     */
    private function looksLikeSetToZeroPhrasing(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        $mentionsZero = (bool) preg_match('/\b0\b|\bzéro\b|\bzero\b/u', $normalized);

        if (!$mentionsZero) {
            return false;
        }

        static $removalVerbs = ['retire', 'retirer', 'supprime', 'supprimer', 'enlève', 'enleve', 'enlever', 'remove', 'delete'];

        foreach ($removalVerbs as $verb) {
            if (preg_match('/\b' . preg_quote($verb, '/') . '\b/u', $normalized)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Actually performs a cart write that was previously proposed and stored
     * by handleAddToCart()/handleUpdateCartItem()/handleRemoveFromCart(), now
     * that the user has confirmed it. No model round-trip needed here — see
     * executeAddToCart()'s docblock — so this just dispatches straight to
     * the real args.
     *
     * @param array{function: string, args: array<string, mixed>} $pending
     */
    private function executePendingAction(array $pending): array
    {
        return match ($pending['function']) {
            'add_to_cart' => $this->executeAddToCart($pending['args']),
            'update_cart_item' => $this->executeUpdateCartItem($pending['args']),
            'remove_from_cart' => $this->executeRemoveFromCart($pending['args']),
            default => ['type' => 'text', 'message' => "Désolé, une erreur interne est survenue."],
        };
    }

    /**
     * @return array{type: 'text', message: string, cartUpdated?: true, link?: string}|array{type: 'products', message: string, products: list<array{name: string, price: float, in_stock: bool, url: string}>}|array{type: 'cart', message: string, items: list<array{name: string, quantity: int, subtotal: float}>, total: float}
     */
    private function resolveResponse(string $userMessage): array
    {
        $messages = $this->buildMessagesFromHistory();

        $result = $this->requestOllama($messages);
        if (!$result['ok']) {
            return $result['error'];
        }

        $message = $result['data']['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];

        // llama3.2 may emit several search_products calls in one response
        // (e.g. one per keyword variant/synonym) — run them all and merge.
        $searchCalls = [];

        foreach ($toolCalls as $toolCall) {
            $function = $toolCall['function'] ?? [];
            $name = $function['name'] ?? null;
            $args = $function['arguments'] ?? [];

            if ('list_categories' === $name) {
                return $this->handleListCategories($messages, $toolCall);
            }

            if ('search_products' === $name) {
                $searchCalls[] = $args;
                continue;
            }

            if ('get_cart_contents' === $name) {
                return $this->handleGetCartContents();
            }

            if ('get_cart_total' === $name) {
                return $this->handleGetCartTotal();
            }

            if ('add_to_cart' === $name) {
                return $this->handleAddToCart($args);
            }

            if ('update_cart_item' === $name) {
                return $this->handleUpdateCartItem($args);
            }

            if ('remove_from_cart' === $name) {
                // Reroutes to update_cart_item(new_quantity: 0) when the user
                // explicitly asked for "quantité 0" / "mettre à 0" rather than
                // a real removal verb — see looksLikeSetToZeroPhrasing() for
                // why (fixes the confusion documented in handleUpdateCartItem()'s
                // Phase 1 test verdict). CartService::update() already removes
                // the line at quantity 0 (see executeUpdateCartItem()), so the
                // end state is identical either way; only the confirmation/
                // reply wording changes to match what the user actually said.
                if ($this->looksLikeSetToZeroPhrasing($userMessage)) {
                    return $this->handleUpdateCartItem(['product_name' => $args['product_name'] ?? '', 'new_quantity' => 0]);
                }

                return $this->handleRemoveFromCart($args);
            }

            if ('get_page_link' === $name) {
                return $this->handleGetPageLink($args);
            }
        }

        if ([] !== $searchCalls) {
            return $this->handleSearchProducts($searchCalls);
        }

        $text = $message['content'] ?? '';

        if ($this->isMalformedToolCallJson($text)) {
            $this->logger->warning('Ollama emitted malformed tool-call-shaped JSON in content instead of structured tool_calls.', ['content' => $text]);

            return [
                'type' => 'text',
                'message' => "Je n'ai pas bien compris votre demande, pouvez-vous reformuler ?",
            ];
        }

        return [
            'type' => 'text',
            'message' => $text !== '' ? $text : "Je n'ai pas compris votre demande, pouvez-vous reformuler ?",
        ];
    }

    /**
     * Detects a known llama3.2 function-calling reliability defect: instead
     * of populating `tool_calls` (which it otherwise does correctly — see
     * class docblock), it sometimes emits its attempted call as free-form,
     * typically malformed JSON text in `content`, e.g.
     * `{"name":"add_to_cart","parameters\":{\"product_name\":\"X\",\"quantity\":3}}`
     * (a real captured example — note the broken escaping). Left as-is, that
     * raw JSON would be shown to the user verbatim as the bot's reply.
     *
     * Deliberately does NOT attempt to parse/recover a match out of the
     * broken JSON — guessing at a malformed payload's intent is exactly the
     * kind of made-up behavior this class avoids everywhere else. This only
     * flags the shape (starts with "{" but doesn't parse), so it won't
     * false-positive on a normal text reply that happens to mention braces
     * mid-sentence, since those don't start the string.
     *
     * Observed frequency (2026-07-30, full 8-function + 3 end-to-end
     * retest): roughly 3 of ~45 real Ollama calls across this session hit
     * this exact defect, always on a parameter-less function
     * (get_cart_contents/get_cart_total/search_products with no real
     * filters) — e.g. `{"name": "get_cart_contents", "parameters": {"}}"`.
     * Every occurrence was caught here and degraded to the same clean
     * "reformulez" reply rather than leaking broken JSON to the user, so
     * this remains a model output-formatting quirk to keep monitoring, not
     * a case needing a new code fix.
     */
    private function isMalformedToolCallJson(string $content): bool
    {
        $trimmed = trim($content);

        if ('' === $trimmed || !str_starts_with($trimmed, '{')) {
            return false;
        }

        json_decode($trimmed);

        return \JSON_ERROR_NONE !== json_last_error();
    }

    /**
     * Converts the session-persisted history (already includes the message
     * just asked) into Ollama's messages format, so every call carries the
     * full conversation instead of just the latest message. The system
     * prompt is added separately in buildRequestPayload().
     *
     * @return list<array<string, mixed>>
     */
    private function buildMessagesFromHistory(): array
    {
        $messages = [];

        foreach ($this->chatHistory->getMessages() as $entry) {
            if ('user' === $entry['role']) {
                $messages[] = ['role' => 'user', 'content' => $entry['message']];
                continue;
            }

            $messages[] = ['role' => 'assistant', 'content' => $this->describeBotEntry($entry)];
        }

        return $messages;
    }

    /**
     * Turns a stored bot turn back into plain text for the model, including
     * the actual product names/prices/stock for a "products" turn so
     * follow-up questions ("et en moins cher ?") stay grounded in what was
     * really shown.
     *
     * @param array<string, mixed> $entry
     */
    private function describeBotEntry(array $entry): string
    {
        if ('products' !== ($entry['type'] ?? null) || empty($entry['products'])) {
            return $entry['message'] ?? '';
        }

        $items = array_map(
            static fn (array $product) => sprintf(
                '%s (%.2f MAD, %s)',
                $product['name'],
                $product['price'],
                ($product['in_stock'] ?? true) ? 'en stock' : 'rupture de stock',
            ),
            $entry['products'],
        );

        return $entry['message'] . ' ' . implode(', ', $items);
    }

    /**
     * Sends the given conversation turns to the local Ollama instance. Unlike
     * a hosted API there's no quota/rate-limit (429) or "overloaded" (503)
     * concept, and no point retrying: a request timeout here almost always
     * means the ~2.7k-token system prompt + tool schema is still being
     * evaluated (see OLLAMA_TIMEOUT_SECONDS), and a second attempt wouldn't
     * reliably be any faster since it's not guaranteed the server-side
     * prompt cache still gets populated once the client has disconnected.
     * The other realistic failures are Ollama not running at all (connection
     * refused) or the model not being pulled (Ollama responds but with an
     * HTTP error) — neither would succeed on retry either.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: array{type: 'text', message: string}}
     */
    private function requestOllama(array $messages): array
    {
        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'json' => $this->buildRequestPayload($messages),
                'timeout' => self::OLLAMA_TIMEOUT_SECONDS,
            ]);

            return ['ok' => true, 'data' => $response->toArray()];
        } catch (TransportExceptionInterface $e) {
            // Request timeout (prompt still being evaluated/model still
            // loading) or a connection failure (Ollama not running at all).
            $this->logger->error('Ollama request timed out or was unreachable.', ['exception' => $e]);

            return ['ok' => false, 'error' => [
                'type' => 'text',
                'message' => "Désolé, l'assistant est momentanément indisponible (modèle IA non chargé ou injoignable). Réessayez dans quelques instants.",
            ]];
        } catch (ExceptionInterface $e) {
            // An HTTP error status from Ollama itself (e.g. model not
            // pulled, malformed request).
            $this->logger->error('Ollama API call failed.', ['exception' => $e]);

            return ['ok' => false, 'error' => [
                'type' => 'text',
                'message' => "Désolé, le service de chat est momentanément indisponible. Réessayez plus tard.",
            ]];
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function buildRequestPayload(array $messages): array
    {
        return [
            'model' => self::MODEL,
            'keep_alive' => self::OLLAMA_KEEP_ALIVE,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_INSTRUCTION],
                ...$messages,
            ],
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_products',
                        'description' => 'Recherche des produits dans le catalogue par catégorie, prix maximum, mot-clé et/ou tri par prix.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'category' => [
                                    'type' => 'string',
                                    'description' => 'Nom de la catégorie de produits (ex: "Informatique", "Vêtements").',
                                ],
                                'max_price' => [
                                    'type' => 'number',
                                    'description' => 'Prix maximum que l\'utilisateur est prêt à payer (une vraie contrainte de budget, ex: "moins de 100 MAD"). N\'utilise JAMAIS ce paramètre pour deviner un prix bas afin de simuler "le moins cher" — utilise sort à la place, voir sa description.',
                                ],
                                'keyword' => [
                                    'type' => 'string',
                                    'description' => 'Mot-clé libre à rechercher dans le nom ou la description du produit.',
                                ],
                                'sort' => [
                                    'type' => 'string',
                                    'enum' => ['cheapest', 'most_expensive'],
                                    'description' => 'Trie les résultats par prix. Utilise "cheapest" quand l\'utilisateur demande LE(S) produit(s) le(s) moins cher(s) ("le moins cher", "the cheapest"), "most_expensive" pour "le plus cher"/"the most expensive". Ce sont des demandes de classement, pas une contrainte de budget : ne remplis jamais max_price à la place pour essayer d\'obtenir le même effet, un seuil deviné trop bas exclurait silencieusement tous les produits réels.',
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'list_categories',
                        'description' => 'Retourne la liste réelle et à jour des catégories de produits disponibles dans la boutique.',
                    ],
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_cart_contents',
                        'description' => 'Retourne le contenu détaillé du panier de l\'utilisateur (nom, quantité, sous-total de chaque article). Lecture seule, ne nécessite aucune confirmation.',
                    ],
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_cart_total',
                        'description' => 'Retourne le montant total actuel du panier de l\'utilisateur. Lecture seule, ne nécessite aucune confirmation.',
                    ],
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'add_to_cart',
                        'description' => 'Propose d\'ajouter une quantité d\'un produit au panier de l\'utilisateur. Le backend n\'exécute jamais l\'ajout au premier appel : il renvoie "pending_confirmation" et n\'exécute réellement l\'action qu\'après confirmation explicite de l\'utilisateur au tour suivant.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'product_name' => [
                                    'type' => 'string',
                                    'description' => 'Nom (ou partie du nom) du produit à ajouter, tel que mentionné par l\'utilisateur.',
                                ],
                                'quantity' => [
                                    'type' => 'integer',
                                    'description' => 'Quantité à ajouter au panier.',
                                ],
                            ],
                            'required' => ['product_name', 'quantity'],
                        ],
                    ],
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'update_cart_item',
                        'description' => 'Propose de changer la quantité d\'un produit déjà présent dans le panier de l\'utilisateur. Le backend n\'exécute jamais le changement au premier appel : il renvoie "pending_confirmation" et n\'exécute réellement l\'action qu\'après confirmation explicite de l\'utilisateur au tour suivant.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'product_name' => [
                                    'type' => 'string',
                                    'description' => 'Nom (ou partie du nom) du produit dont il faut changer la quantité.',
                                ],
                                'new_quantity' => [
                                    'type' => 'integer',
                                    'description' => 'Nouvelle quantité souhaitée pour ce produit dans le panier.',
                                ],
                            ],
                            'required' => ['product_name', 'new_quantity'],
                        ],
                    ],
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'remove_from_cart',
                        'description' => 'Propose de retirer complètement un produit du panier de l\'utilisateur. Le backend n\'exécute jamais le retrait au premier appel : il renvoie "pending_confirmation" et n\'exécute réellement l\'action qu\'après confirmation explicite de l\'utilisateur au tour suivant.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'product_name' => [
                                    'type' => 'string',
                                    'description' => 'Nom (ou partie du nom) du produit à retirer du panier.',
                                ],
                            ],
                            'required' => ['product_name'],
                        ],
                    ],
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_page_link',
                        'description' => 'Retourne l\'URL exacte d\'une page connue du site pour y emmener l\'utilisateur (ex: "amène-moi au panier"). Traduis la demande de l\'utilisateur, quelle que soit sa formulation ou sa langue, vers l\'une des trois valeurs exactes attendues par le paramètre page.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'page' => [
                                    'type' => 'string',
                                    'enum' => ['home', 'cart', 'dashboard'],
                                    'description' => '"home" pour la page d\'accueil/catalogue, "cart" pour le panier, "dashboard" pour le tableau de bord analytique.',
                                ],
                            ],
                            'required' => ['page'],
                        ],
                    ],
                ],
            ],
            'stream' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $searchCalls one args array per search_products call
     *
     * Phase 1 test verdict (2026-07-28): Partiellement fiable (2/3 en test)
     * — le modèle échoue parfois à mapper un nom de catégorie non-anglais
     * (ex: "vêtements") vers le vrai nom de catégorie en base, le traitant
     * comme un mot-clé libre au lieu d'une catégorie.
     *
     * Phase 2 test verdict, enchaînement search_products puis add_to_cart
     * (2026-07-28) : Non fiable (0/3 de bout en bout) — quand la recherche
     * renvoie plusieurs résultats, une référence implicite au tour suivant
     * ("ajoute-le") ne résout jamais vers un nom de produit précis (voir
     * handleAddToCart()) ; ce premier appel a aussi échoué à renvoyer des
     * résultats dans 2/3 essais alors que la recherche aurait dû réussir.
     *
     * Phase 2 test verdict, enchaînement list_categories puis
     * search_products (2026-07-28) : Partiellement fiable (2/3) — une fois
     * qu'une catégorie a été énoncée par l'utilisateur en réponse à
     * list_categories (ex: "livres"), le mapping vers le vrai nom de
     * catégorie en base et l'appel de cette fonction réussissent dans 2
     * essais sur 3 ; dans le troisième, aucun appel n'est déclenché.
     *
     * Phase 3 test verdict (2026-07-30), 5 essais par variante : mapping
     * catégorie française ("montre-moi des vêtements" → category:
     * "Clothing") Fiable (5/5), une nette amélioration par rapport au 2/3
     * de la Phase 1 — plus reproduit sur cet échantillon. Recherche par
     * catégorie explicite (Electronics) Fiable (5/5). Recherche par prix
     * (max_price) Fiable (3/5) — 1 échec dû à un bug alors réel (keyword
     * envoyé comme la chaîne littérale "null", non normalisée avant cette
     * date — voir normalizeKeywordArg(), corrigé et revérifié depuis) et 1
     * échec dû au défaut JSON malformé déjà documenté ailleurs (voir
     * isMalformedToolCallJson()) — aucun des deux n'est resté un problème de
     * catégorie/prix. Tri (`sort`) Fiable (5/5) sur "Give me cheapest
     * clothes", toujours correctement trié du moins cher au plus cher.
     */
    private function handleSearchProducts(array $searchCalls): array
    {
        $productsById = [];
        // A "cheapest"/"most_expensive" sort request is about the overall
        // search intent, not any one call, so it's collected across all
        // calls and applied once to the final merged list below rather than
        // trusted to survive per-call DB ordering through the merge.
        $sort = null;

        foreach ($searchCalls as $args) {
            $requestedCategory = $this->normalizeCategoryArg($args['category'] ?? null);
            $keyword = $this->normalizeKeywordArg($args['keyword'] ?? null);
            $sort ??= $this->normalizeSortArg($args['sort'] ?? null);

            if (null !== $requestedCategory && \is_string($keyword) && $this->looksLikeSpecificProductName($keyword)) {
                // A precise, multi-word product name is a more trustworthy
                // signal than category here — see
                // looksLikeSpecificProductName() for why.
                $requestedCategory = null;
            }

            $categorySlug = $this->resolveCategorySlug($requestedCategory);

            // A category was explicitly requested but didn't match any real
            // category (e.g. the model passed a name/language variant that
            // doesn't exist in the DB): this call yields nothing, rather than
            // silently dropping the filter and searching the whole catalog.
            if (null !== $requestedCategory && null === $categorySlug) {
                continue;
            }

            $maxPrice = $this->normalizeMaxPriceArg($args['max_price'] ?? null);

            foreach ($this->productRepository->search($categorySlug, $keyword, $maxPrice) as $product) {
                $productsById[$product->getId()] = $product;
            }
        }

        if ([] === $productsById) {
            // A search that found nothing shouldn't leave a stale previous
            // result around for a later vague add_to_cart reference to
            // latch onto — see the last-search bookkeeping below.
            $this->lastSearch->clear();

            return [
                'type' => 'text',
                'message' => "Je n'ai trouvé aucun produit correspondant à votre recherche.",
            ];
        }

        // Remembered so a follow-up add_to_cart with an empty/vague
        // product_name (or one that's just the raw search keyword echoed
        // back) can resolve to what was actually just found instead of
        // re-running a search on that same raw text — see
        // handleAddToCart()'s Phase 2 test verdict and
        // looksLikeUnresolvedProductReference().
        $keywords = array_values(array_unique(array_filter(array_map(
            fn (array $args) => $this->normalizeKeywordArg($args['keyword'] ?? null),
            $searchCalls,
        ))));

        $this->lastSearch->set(
            array_keys($productsById),
            array_map(static fn (Product $product) => $product->getName(), $productsById),
            $keywords,
        );

        $results = [];
        foreach ($productsById as $product) {
            $results[] = [
                'name' => $product->getName(),
                'price' => (float) $product->getPrice(),
                'in_stock' => $product->getStock() > 0,
                'url' => $this->urlGenerator->generate('app_product_show', [
                    'id' => $product->getId(),
                ]),
            ];
        }

        if (null !== $sort) {
            usort(
                $results,
                static fn (array $a, array $b) => 'price_asc' === $sort
                    ? $a['price'] <=> $b['price']
                    : $b['price'] <=> $a['price'],
            );
        }

        return [
            'type' => 'products',
            'message' => sprintf('%d produit(s) trouvé(s) :', count($results)),
            'products' => $results,
        ];
    }

    /**
     * Executes the list_categories call locally, then sends the real
     * category names back to the model as a tool result so it can phrase
     * the final answer itself — in whatever language the user asked in.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $toolCall
     *
     * Phase 1 test verdict (2026-07-28): Fiable (3/3 en test).
     *
     * Phase 2 test verdict, enchaînement list_categories puis
     * search_products (2026-07-28) : Partiellement fiable (2/3) — voir
     * handleSearchProducts() pour le détail. Le déclenchement de
     * list_categories lui-même reste fiable (3/3), même si la langue de la
     * réponse formulée par le modèle varie d'un essai à l'autre (français
     * traduit vs noms anglais bruts de la base) alors que l'utilisateur a
     * posé sa question en français.
     */
    private function handleListCategories(array $messages, array $toolCall): array
    {
        $categoryNames = array_map(
            static fn ($category) => $category->getName(),
            $this->categoryRepository->findAll(),
        );

        return $this->respondWithFunctionResult($messages, $toolCall, 'list_categories', [
            'categories' => $categoryNames,
        ]);
    }

    /**
     * Executes get_cart_contents locally and returns its exact contents for
     * the frontend to render directly — deliberately does NOT round-trip
     * through the model to phrase quantities/subtotals in natural language:
     * confirmed reproducibly unreliable at restating those exact numbers
     * (see class docblock), even though the data it would have been handed
     * was always correct. These figures come straight from CartService, so
     * this path is guaranteed accurate. $message is still a short,
     * backend-authored sentence (not model-generated) so the reply doesn't
     * read as a bare data dump.
     *
     * Phase 1 test verdict (2026-07-28):  Fiable (3/3) — chiffres non
     * reformulés par le modèle (bypass volontaire), le test valide le bon
     * déclenchement de la fonction.
     *
     * Phase 2 test verdict, enchaînement get_cart_contents puis
     * update_cart_item (2026-07-28) : Non fiable (1/3) — le déclenchement de
     * get_cart_contents lui-même reste fiable (3/3), mais une référence
     * implicite au tour suivant ("cet article") n'est résolue vers le nom
     * réel de l'article du panier que dans 1 essai sur 3 ; dans les deux
     * autres, le modèle transmet la référence littérale ("cet article",
     * "article nommé ici") comme product_name à update_cart_item, ce qui
     * échoue en not_found — voir handleUpdateCartItem().
     */
    private function handleGetCartContents(): array
    {
        $items = $this->cartService->getItems();

        if ([] === $items) {
            return [
                'type' => 'text',
                'message' => 'Votre panier est vide.',
            ];
        }

        return [
            'type' => 'cart',
            'message' => 'Voici le contenu de votre panier :',
            'items' => array_map(
                static fn (array $item) => [
                    'name' => $item['product']->getName(),
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['subtotal'],
                ],
                $items,
            ),
            'total' => $this->cartService->getTotal(),
        ];
    }

    /**
     * Same reasoning as handleGetCartContents(): the total is formatted here
     * directly from CartService's real figure, never restated by the model.
     *
     * Phase 1 test verdict (2026-07-28): Fiable sur le montant (3/3) —
     * réponse toujours en français par choix de conception (message fixe
     * backend), pas adaptative à la langue de l'utilisateur.
     *
     * Phase 2 test verdict, enchaînement add_to_cart puis get_cart_total
     * (2026-07-28) : Fiable (3/3) — après confirmation d'un add_to_cart,
     * une question de suivi sur le total du panier déclenche
     * systématiquement get_cart_total et renvoie le montant exact reflétant
     * l'ajout (157.89 MAD sur les 3 essais, cohérent avec 3× 52.63 MAD).
     */
    private function handleGetCartTotal(): array
    {
        $total = $this->cartService->getTotal();

        if (0.0 === $total) {
            return [
                'type' => 'text',
                'message' => 'Votre panier est vide, le total est de 0 MAD.',
            ];
        }

        return [
            'type' => 'text',
            'message' => sprintf('Le montant total de votre panier est de %s MAD.', number_format($total, 2, '.', ' ')),
        ];
    }

    /**
     * Resolves the target product by name, but does NOT call CartService::add()
     * yet: on a clean match it stores the call as a pending action and asks
     * the user to confirm. The actual mutation only happens in
     * executeAddToCart(), once ask() has verified the next message is a real
     * confirmation — this is what makes the confirm-before-mutating rule a
     * backend guarantee rather than a prompt request.
     *
     * The confirmation question (and the not_found/ambiguous replies) is a
     * fixed, backend-authored message built from the product actually
     * resolved by findProductByName() — the same one stored in
     * ChatPendingActionService — never phrased by the model. Ollama's only
     * job at this step is deciding to call add_to_cart and with which
     * product_name/quantity extracted from the user's message; letting it
     * also phrase the confirmation text risked it substituting a different
     * (sometimes hallucinated) product name than the one actually resolved
     * and stored — same class of bug fixed on get_page_link/get_cart_*.
     *
     * @param array<string, mixed> $args
     *
     * Phase 1 test verdict (2026-07-28): Fiable (3/3).
     *
     * Phase 2 test verdict, enchaînement search_products puis add_to_cart
     * (2026-07-28) : Non fiable (0/3 de bout en bout) — voir
     * handleSearchProducts() pour le détail : sur les 3 essais, une
     * référence implicite ("ajoute-le") à un produit trouvé juste avant
     * aboutit toujours à un product_name égal au mot-clé de recherche brut
     * plutôt qu'au nom du produit visé, ce qui déclenche systématiquement
     * la branche "ambiguous" au lieu d'une confirmation propre.
     *
     * Phase 2 test verdict, enchaînement add_to_cart puis get_cart_total
     * (2026-07-28) : Fiable (3/3) — voir handleGetCartTotal().
     *
     * Phase 3 test verdict (2026-07-30) : les essais précédents (3/3, 0/3)
     * portaient sur des phrasings impératifs ("ajoute X à mon panier") que
     * detectDirectCartAction() intercepte avant même d'atteindre Ollama —
     * confirmé encore Fiable (3/3) sur ce chemin. Mais forcé à passer par
     * Ollama avec un phrasing volontairement non-impératif ("je voudrais
     * avoir 2 X dans mon panier"), Non fiable (1/5) : le modèle a appelé
     * remove_from_cart au lieu d'add_to_cart dans 4 essais sur 5 — un choix
     * de fonction entièrement erroné (arguments bien formés, juste la
     * mauvaise fonction), pas un bug de dispatch corrigeable ici. C'est
     * exactement le mécanisme que detectDirectCartAction() existe déjà pour
     * contourner ; ce nouveau chiffre quantifie pourquoi ce contournement
     * reste nécessaire plutôt que de révéler un défaut nouveau.
     */
    private function handleAddToCart(array $args): array
    {
        $productName = (string) ($args['product_name'] ?? '');
        $quantity = (int) ($args['quantity'] ?? 0);

        $lastSearch = $this->lastSearch->get();
        $resolvedFromLastSearch = null !== $lastSearch
            && [] !== $lastSearch['product_ids']
            && $this->looksLikeUnresolvedProductReference($productName, $lastSearch);

        $match = $resolvedFromLastSearch
            ? $this->resolveFromLastSearch($lastSearch)
            : $this->findProductByName($productName);

        if ('not_found' === $match['status']) {
            return [
                'type' => 'text',
                'message' => $resolvedFromLastSearch
                    ? "Désolé, ce produit n'est plus disponible dans le catalogue. Pouvez-vous préciser lequel vous voulez ?"
                    : sprintf('Désolé, je n\'ai pas trouvé de produit correspondant à "%s". Pouvez-vous préciser ?', $productName),
            ];
        }

        if ('ambiguous' === $match['status']) {
            return [
                'type' => 'text',
                'message' => $resolvedFromLastSearch
                    ? sprintf('Plusieurs produits correspondaient à votre recherche précédente : %s. Lequel voulez-vous ajouter ?', implode(', ', $match['candidates']))
                    : sprintf('Plusieurs produits correspondent à "%s" : %s. Lequel voulez-vous ?', $productName, implode(', ', $match['candidates'])),
            ];
        }

        $product = $match['product'];

        $this->pendingAction->set('add_to_cart', [
            'product_id' => $product->getId(),
            'product_name' => $product->getName(),
            'quantity' => $quantity,
        ]);

        return [
            'type' => 'text',
            'message' => sprintf('Vous voulez que j\'ajoute %d× "%s" à votre panier, c\'est bien ça ?', $quantity, $product->getName()),
        ];
    }

    /**
     * Runs the add_to_cart call stored by handleAddToCart() once the user has
     * confirmed it. Re-fetches the product by id rather than trusting stock
     * figures from propose-time, since stock may have changed in between.
     *
     * Replies with a fixed, backend-authored message built directly from the
     * real product name/quantity/stock figures — no model round-trip. Same
     * reasoning as handleGetPageLink(): a confirmation round-trip here would
     * ask the model to restate numbers, including ones it was never even
     * given (e.g. a cart total, which isn't part of this function's
     * result), and that's been shown reproducibly unreliable.
     *
     * @param array<string, mixed> $args
     */
    private function executeAddToCart(array $args): array
    {
        $product = $this->productRepository->find($args['product_id'] ?? null);

        if (!$product) {
            return [
                'type' => 'text',
                'message' => sprintf('Désolé, "%s" n\'est plus disponible dans le catalogue.', $args['product_name'] ?? ''),
            ];
        }

        $quantity = (int) ($args['quantity'] ?? 0);

        try {
            $this->cartService->add($product, $quantity);
        } catch (\InvalidArgumentException $e) {
            return [
                'type' => 'text',
                'message' => sprintf('Impossible d\'ajouter "%s" : stock insuffisant (%d disponible(s)).', $product->getName(), $product->getStock()),
            ];
        }

        return [
            'type' => 'text',
            'message' => sprintf('%d× "%s" ajouté(s) à votre panier.', $quantity, $product->getName()),
            'cartUpdated' => true,
        ];
    }

    /**
     * Resolves the target product by name and stores the update as a pending
     * action instead of calling CartService::update() immediately — see
     * handleAddToCart() for why, including why the confirmation question is
     * a fixed message built from the resolved product rather than
     * model-phrased.
     *
     * @param array<string, mixed> $args
     *
     * Phase 1 test verdict (2026-07-28):  Partiellement fiable (2/3) — le
     * modèle confond parfois une demande de "quantité à 0" avec une intention
     * de suppression (appelle remove_from_cart au lieu d'update_cart_item
     * avec quantity=0).
     *
     * Phase 2 test verdict, enchaînement get_cart_contents puis
     * update_cart_item (2026-07-28) : Non fiable (1/3) — voir
     * handleGetCartContents() pour le détail des 3 essais.
     *
     * Phase 3 test verdict (2026-07-30) : via le phrasing impératif
     * "change la quantité de X à N" intercepté par detectDirectCartAction(),
     * Fiable (3/3), confirmant que ce chemin reste solide. Forcé à passer
     * par Ollama avec un phrasing non-impératif ("peux-tu mettre la
     * quantité de X à 5"), Non fiable (0/5) : le modèle a systématiquement
     * appelé add_to_cart(quantity: 5) au lieu d'update_cart_item — une
     * fonction entièrement erronée, pas de mauvais arguments à corriger
     * dans le dispatch. Même conclusion que pour handleAddToCart() : ce
     * chiffre quantifie la nécessité du contournement déterministe
     * existant plutôt qu'un nouveau défaut.
     */
    private function handleUpdateCartItem(array $args): array
    {
        $productName = (string) ($args['product_name'] ?? '');
        $newQuantity = (int) ($args['new_quantity'] ?? 0);

        $match = $this->findProductByName($productName, preferCartMatch: true);

        if ('not_found' === $match['status']) {
            return [
                'type' => 'text',
                'message' => sprintf('Désolé, je n\'ai pas trouvé de produit correspondant à "%s". Pouvez-vous préciser ?', $productName),
            ];
        }

        if ('ambiguous' === $match['status']) {
            return [
                'type' => 'text',
                'message' => sprintf('Plusieurs produits correspondent à "%s" : %s. Lequel voulez-vous ?', $productName, implode(', ', $match['candidates'])),
            ];
        }

        $product = $match['product'];

        $this->pendingAction->set('update_cart_item', [
            'product_id' => $product->getId(),
            'product_name' => $product->getName(),
            'new_quantity' => $newQuantity,
        ]);

        return [
            'type' => 'text',
            'message' => sprintf('Vous voulez changer la quantité de "%s" à %d, c\'est bien ça ?', $product->getName(), $newQuantity),
        ];
    }

    /**
     * Runs the update_cart_item call stored by handleUpdateCartItem() once
     * the user has confirmed it. Same fixed-message reasoning as
     * executeAddToCart() — no model round-trip for the confirmation.
     *
     * @param array<string, mixed> $args
     */
    private function executeUpdateCartItem(array $args): array
    {
        $product = $this->productRepository->find($args['product_id'] ?? null);

        if (!$product) {
            return [
                'type' => 'text',
                'message' => sprintf('Désolé, "%s" n\'est plus disponible dans le catalogue.', $args['product_name'] ?? ''),
            ];
        }

        $newQuantity = (int) ($args['new_quantity'] ?? 0);

        try {
            $this->cartService->update($product, $newQuantity);
        } catch (\InvalidArgumentException $e) {
            return [
                'type' => 'text',
                'message' => sprintf('Impossible de mettre à jour "%s" : stock insuffisant (%d disponible(s)).', $product->getName(), $product->getStock()),
            ];
        }

        // CartService::update() removes the line entirely when the new
        // quantity is 0 — worth its own message rather than "mis à jour : 0".
        if (0 === $newQuantity) {
            return [
                'type' => 'text',
                'message' => sprintf('"%s" retiré de votre panier.', $product->getName()),
                'cartUpdated' => true,
            ];
        }

        return [
            'type' => 'text',
            'message' => sprintf('Quantité de "%s" mise à jour : %d.', $product->getName(), $newQuantity),
            'cartUpdated' => true,
        ];
    }

    /**
     * Resolves the target product by name and stores the removal as a
     * pending action instead of calling CartService::remove() immediately —
     * see handleAddToCart() for why, including why the confirmation question
     * is a fixed message built from the resolved product rather than
     * model-phrased.
     *
     * When more than one unit is already in the cart, "retirer" is
     * ambiguous — all of them, or just some? — so this asks explicitly
     * instead of defaulting to removing everything. That reply is handled
     * by resolveRemoveQuantityChoice(), not the generic yes/no confirmation
     * used for every other pending action, since it needs to recognize a
     * quantity as well as a plain "oui". With 0 or 1 unit in the cart
     * there's nothing to disambiguate, so this keeps the plain yes/no flow.
     *
     * @param array<string, mixed> $args
     *
     * Phase 1 test verdict (2026-07-28): Fiable (3/3), y compris les
     * sous-flux de désambiguïsation quantité.
     */
    private function handleRemoveFromCart(array $args): array
    {
        $productName = (string) ($args['product_name'] ?? '');

        $match = $this->findProductByName($productName, preferCartMatch: true);

        if ('not_found' === $match['status']) {
            return [
                'type' => 'text',
                'message' => sprintf('Désolé, je n\'ai pas trouvé de produit correspondant à "%s". Pouvez-vous préciser ?', $productName),
            ];
        }

        if ('ambiguous' === $match['status']) {
            return [
                'type' => 'text',
                'message' => sprintf('Plusieurs produits correspondent à "%s" : %s. Lequel voulez-vous ?', $productName, implode(', ', $match['candidates'])),
            ];
        }

        $product = $match['product'];
        $currentQuantity = $this->cartService->getCart()[$product->getId()] ?? 0;

        if ($currentQuantity > 1) {
            $this->pendingAction->set('remove_from_cart_quantity_choice', [
                'product_id' => $product->getId(),
                'product_name' => $product->getName(),
            ]);

            return [
                'type' => 'text',
                'message' => sprintf(
                    'Vous avez %d× "%s" dans votre panier. Voulez-vous tout retirer (%d) ou une quantité précise ?',
                    $currentQuantity,
                    $product->getName(),
                    $currentQuantity,
                ),
            ];
        }

        $this->pendingAction->set('remove_from_cart', [
            'product_id' => $product->getId(),
            'product_name' => $product->getName(),
        ]);

        return [
            'type' => 'text',
            'message' => sprintf('Vous voulez retirer "%s" de votre panier, c\'est bien ça ?', $product->getName()),
        ];
    }

    /**
     * Resolves the reply to handleRemoveFromCart()'s "tout retirer ou une
     * quantité précise ?" clarification. Recognizes either a "remove
     * everything" confirmation (the same words as looksLikeConfirmation(),
     * plus "tout"/"tous"/"toute"/"toutes") or an explicit number of units to
     * remove. Returns null when neither is recognized, so ask() treats it
     * the same as a declined/cancelled confirmation elsewhere in this class
     * — the safe default, since guessing here risks removing more or less
     * than the user actually meant.
     *
     * @param array{product_id: int, product_name: string} $args
     */
    private function resolveRemoveQuantityChoice(string $userMessage, array $args): ?array
    {
        $product = $this->productRepository->find($args['product_id'] ?? null);

        if (!$product) {
            return [
                'type' => 'text',
                'message' => sprintf('Désolé, "%s" n\'est plus disponible dans le catalogue.', $args['product_name'] ?? ''),
            ];
        }

        $normalized = mb_strtolower(trim($userMessage));
        $normalized = preg_replace('/[^\p{L}\p{N}\s\'-]/u', '', $normalized) ?? $normalized;

        if ($this->looksLikeConfirmation($userMessage) || preg_match('/\b(tout|tous|toute|toutes)\b/u', $normalized)) {
            return $this->executeRemoveFromCart(['product_id' => $product->getId(), 'product_name' => $product->getName()]);
        }

        // Same reasoning as the generic decline handling in ask(): answer an
        // explicit "non" here directly rather than falling through to
        // Ollama, which would otherwise risk re-triggering the same
        // quantity-choice question from conversation context.
        if ($this->looksLikeDecline($userMessage)) {
            return [
                'type' => 'text',
                'message' => sprintf('D\'accord, je ne retire rien de "%s".', $product->getName()),
            ];
        }

        if (preg_match('/(\d+)/', $normalized, $matches)) {
            return $this->executePartialRemoveFromCart($product, (int) $matches[1]);
        }

        return null;
    }

    /**
     * Removes $requestedQuantity units of $product from the cart — the
     * "quantité précise" branch of resolveRemoveQuantityChoice(). Falls
     * back to a full removal if the requested amount is at least what's
     * actually in the cart, so "retire 10" on a cart with 3 doesn't error
     * out or leave a negative-sounding state.
     */
    private function executePartialRemoveFromCart(Product $product, int $requestedQuantity): array
    {
        $currentQuantity = $this->cartService->getCart()[$product->getId()] ?? 0;

        if ($currentQuantity <= 0) {
            return [
                'type' => 'text',
                'message' => sprintf('"%s" n\'était déjà pas dans votre panier.', $product->getName()),
            ];
        }

        if ($requestedQuantity >= $currentQuantity) {
            return $this->executeRemoveFromCart(['product_id' => $product->getId(), 'product_name' => $product->getName()]);
        }

        if ($requestedQuantity <= 0) {
            return [
                'type' => 'text',
                'message' => sprintf('D\'accord, je ne retire rien de "%s".', $product->getName()),
            ];
        }

        $newQuantity = $currentQuantity - $requestedQuantity;
        $this->cartService->update($product, $newQuantity);

        return [
            'type' => 'text',
            'message' => sprintf('%d× "%s" retiré(s) de votre panier (il en reste %d).', $requestedQuantity, $product->getName(), $newQuantity),
            'cartUpdated' => true,
        ];
    }

    /**
     * Runs the remove_from_cart call stored by handleRemoveFromCart() once
     * the user has confirmed it. Same fixed-message reasoning as
     * executeAddToCart() — no model round-trip for the confirmation.
     *
     * @param array<string, mixed> $args
     */
    private function executeRemoveFromCart(array $args): array
    {
        $product = $this->productRepository->find($args['product_id'] ?? null);

        if (!$product) {
            return [
                'type' => 'text',
                'message' => sprintf('Désolé, "%s" n\'est plus disponible dans le catalogue.', $args['product_name'] ?? ''),
            ];
        }

        $wasInCart = array_key_exists($product->getId(), $this->cartService->getCart());

        $this->cartService->remove($product);

        if (!$wasInCart) {
            return [
                'type' => 'text',
                'message' => sprintf('"%s" n\'était déjà pas dans votre panier.', $product->getName()),
            ];
        }

        return [
            'type' => 'text',
            'message' => sprintf('"%s" retiré de votre panier.', $product->getName()),
            'cartUpdated' => true,
        ];
    }

    /**
     * Resolves a known site page to its real URL. Deliberately does NOT
     * round-trip through the model to phrase the accompanying text:
     * get_page_link is pure navigation with no product/cart data at stake,
     * and doing so let the model fabricate fake cart contents out of thin
     * air — confirmed reproducible, and traced to it echoing the system
     * prompt's own illustrative example ("vous avez 2x Casque Bluetooth
     * dans votre panier") as if it were real data, since it's the only
     * cart-shaped text anywhere in its context on an empty-cart session. A
     * fixed, backend-authored message plus the resolved URL sidesteps this
     * structurally rather than relying on a prompt instruction (already
     * shown unreliable) to stop it. The URL is rendered as a real clickable
     * <a> built straight from this trusted value, never from freeform model
     * text.
     *
     * Update (2026-07-30): the root cause — the literal "Casque Bluetooth"/
     * "Écouteurs Bluetooth" example strings themselves — has now been
     * removed from SYSTEM_INSTRUCTION entirely (replaced with an abstract
     * "[nom du produit]" placeholder), after the same fictional product name
     * was suspected (though never actually reproduced live, 8/8 clean) in a
     * list_categories reply. Eliminated at the source for both call sites
     * that shared it, even though this method's own bypass above already
     * made it moot here specifically — belt-and-suspenders, since a fixed
     * backend message can't hallucinate but a shared prompt example can
     * still poison some other, less-guarded call site later.
     *
     * @param array<string, mixed> $args
     *
     * Phase 1 test verdict (2026-07-28):  Fiable (3/3).
     */
    private function handleGetPageLink(array $args): array
    {
        $page = (string) ($args['page'] ?? '');
        $url = $this->resolvePageUrl($page);

        if (null === $url) {
            return [
                'type' => 'text',
                'message' => "Désolé, je ne connais pas cette page.",
            ];
        }

        return [
            'type' => 'text',
            'message' => self::PAGE_LINK_MESSAGES[$page] ?? 'Voici le lien demandé :',
            'link' => $url,
        ];
    }

    private function resolvePageUrl(string $page): ?string
    {
        return match ($page) {
            'home' => $this->urlGenerator->generate('app_home'),
            'cart' => $this->urlGenerator->generate('app_cart'),
            'dashboard' => $this->urlGenerator->generate('app_dashboard'),
            default => null,
        };
    }

    /**
     * Finds the product a name argument refers to, reusing search_products'
     * underlying ProductRepository::search() rather than requiring an exact
     * match, since the model forwards whatever wording the user typed.
     *
     * $preferCartMatch adds a third disambiguation pass, on top of the
     * existing exact-name one, for remove_from_cart/update_cart_item: their
     * target must already be in the cart, and llama3.2's product_name
     * extraction has been observed to truncate multi-word names (e.g. "Quis
     * Labore Dolor" sent as just "Quis" — confirmed via logs, not a search
     * bug), which then matches many unrelated catalog products loosely. When
     * that happens, narrowing the matches down to ones actually present in
     * the cart usually leaves exactly one, since it's rare for more than one
     * cart item to share a truncated keyword. This only ever narrows an
     * otherwise-ambiguous result; it never overrides a clean single/exact
     * match. It's deliberately NOT used for add_to_cart, since there's no
     * existing cart item to anchor a truncated name against there.
     *
     * Residual limitation: if the truncated term still matches more than one
     * cart item (or matches none of them), this still falls through to
     * "ambiguous"/asks for clarification — there's no further fallback for
     * that case, since guessing between two real cart items would risk
     * silently acting on the wrong one.
     *
     * @return array{status: 'found', product: Product}|array{status: 'not_found'}|array{status: 'ambiguous', candidates: list<string>}
     */
    private function findProductByName(string $name, bool $preferCartMatch = false): array
    {
        $matches = $this->productRepository->search(null, $name);

        if ([] === $matches) {
            return ['status' => 'not_found'];
        }

        if (1 === count($matches)) {
            return ['status' => 'found', 'product' => $matches[0]];
        }

        // Several products match the keyword loosely, but only one might
        // match the name exactly (e.g. "Écouteurs" also matching "Écouteurs
        // Bluetooth Pro") — prefer that instead of asking for clarification.
        $exactMatches = array_values(array_filter(
            $matches,
            static fn (Product $product) => 0 === strcasecmp($product->getName(), $name),
        ));

        if (1 === count($exactMatches)) {
            return ['status' => 'found', 'product' => $exactMatches[0]];
        }

        if ($preferCartMatch) {
            $cartProductIds = array_keys($this->cartService->getCart());
            $inCartMatches = array_values(array_filter(
                $matches,
                static fn (Product $product) => in_array($product->getId(), $cartProductIds, true),
            ));

            if (1 === count($inCartMatches)) {
                return ['status' => 'found', 'product' => $inCartMatches[0]];
            }
        }

        return [
            'status' => 'ambiguous',
            'candidates' => array_map(static fn (Product $product) => $product->getName(), $matches),
        ];
    }

    /**
     * Detects a product_name argument that isn't really a product
     * reference: empty, a generic pronoun-like placeholder ("ajoute-le",
     * "achète cet article"), a literal echo of the keyword used by the
     * previous search_products call, or — widened here — anything else that
     * simply doesn't match any real product in the catalog either. Used by
     * handleAddToCart() to decide whether to resolve against
     * ChatLastSearchService instead of running findProductByName() on this
     * raw text, which would otherwise search the whole catalog and fail.
     *
     * The three narrow, syntactic checks (empty/placeholder/keyword-echo)
     * were the shapes llama3.2 was originally observed sending for
     * add_to_cart when the user referred back to a search result shown just
     * before (see handleAddToCart()'s Phase 2 test verdict). But a category-
     * or price-driven search (e.g. "montre-moi des vêtements pas chers" —
     * category="Clothing", keyword="") leaves the `keywords` list empty, so
     * a fourth shape slips past all three: the model handing back the
     * user's *entire preceding request phrase* ("vêtements pas chers") as
     * product_name — not empty, not a pronoun, and not equal to any stored
     * keyword because there wasn't one to echo. Confirmed via live testing
     * that this produced a doomed findProductByName("vêtements pas chers")
     * → not_found, even though ChatLastSearchService had a perfectly good
     * recent result to fall back on. Rather than special-case that one
     * phrase, this widens the definition to its logical conclusion: if
     * $productName doesn't resolve to any real product at all, a recent
     * search result is a better bet than failing outright, regardless of
     * what shape the unresolved text happens to take. This does mean one
     * extra catalog search (to check "does anything match"), reusing the
     * exact same query findProductByName() would otherwise have run — no
     * duplicate work, since a true match here still gets handled by the
     * normal findProductByName() path in handleAddToCart() (this only
     * returns true when that search would have come back empty).
     *
     * @param array{product_ids: list<int>, product_names: list<string>, keywords: list<string>} $lastSearch
     */
    private function looksLikeUnresolvedProductReference(string $productName, array $lastSearch): bool
    {
        $normalized = mb_strtolower(trim($productName));
        $normalized = preg_replace("/[^\p{L}\p{N}\s'-]/u", '', $normalized) ?? $normalized;

        if ('' === $normalized) {
            return true;
        }

        static $genericReferences = [
            'le', 'la', 'l\'article', 'cet article', 'cette article', 'ce produit',
            'cet produit', 'celui-ci', 'celui-la', 'celui-là', 'ca', 'ça',
            'l\'objet', 'ce dernier', 'le produit', 'l\'item', 'cet item',
            'it', 'this', 'this one', 'that one', 'the item', 'the product',
        ];

        if (in_array($normalized, $genericReferences, true)) {
            return true;
        }

        foreach ($lastSearch['keywords'] as $keyword) {
            if ($normalized === mb_strtolower(trim($keyword))) {
                return true;
            }
        }

        return [] === $this->productRepository->search(null, $productName);
    }

    /**
     * Resolves an implicit product reference against the last remembered
     * search_products result: the single product if the search found
     * exactly one, otherwise the same "ambiguous" shape findProductByName()
     * would return, built from the candidates that search actually
     * returned rather than re-searching on the raw (empty/vague) text.
     *
     * @param array{product_ids: list<int>, product_names: list<string>, keywords: list<string>} $lastSearch
     *
     * @return array{status: 'found', product: Product}|array{status: 'not_found'}|array{status: 'ambiguous', candidates: list<string>}
     */
    private function resolveFromLastSearch(array $lastSearch): array
    {
        if (1 === count($lastSearch['product_ids'])) {
            $product = $this->productRepository->find($lastSearch['product_ids'][0]);

            return null !== $product
                ? ['status' => 'found', 'product' => $product]
                : ['status' => 'not_found'];
        }

        return [
            'status' => 'ambiguous',
            'candidates' => $lastSearch['product_names'],
        ];
    }

    /**
     * Sends a locally-computed function result back to the model as a tool
     * message so it can phrase the final answer itself, in whatever
     * language the user asked in.
     *
     * $cartMutated flags a response as having actually changed the cart's
     * contents (as opposed to a read, or a write call that turned out to be
     * a no-op/error), so the frontend knows to refresh the navbar dropdown.
     *
     * $link carries a backend-resolved URL (e.g. from get_page_link) so the
     * frontend can render a real clickable link built from this trusted
     * value, independent of whatever text the model's follow-up call
     * produces or whether that follow-up call even succeeds.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $toolCall
     * @param array<string, mixed>       $responseData
     */
    private function respondWithFunctionResult(array $messages, array $toolCall, string $functionName, array $responseData, bool $cartMutated = false, ?string $link = null): array
    {
        // json_decode() turns an empty `arguments: {}` into a PHP `[]`, which
        // json_encode() would then send back as a JSON array — Ollama
        // expects tool_calls[].function.arguments to stay an object.
        if (isset($toolCall['function']['arguments']) && [] === $toolCall['function']['arguments']) {
            $toolCall['function']['arguments'] = new \stdClass();
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [$toolCall],
        ];
        $messages[] = [
            'role' => 'tool',
            'name' => $functionName,
            'content' => json_encode($responseData),
        ];

        $result = $this->requestOllama($messages);
        if (!$result['ok']) {
            // The cart write / URL resolution already happened by this point
            // (only the follow-up call that phrases the reply failed), so
            // these flags must still be reported rather than silently dropped.
            return [...$result['error'], ...($cartMutated ? ['cartUpdated' => true] : []), ...(null !== $link ? ['link' => $link] : [])];
        }

        $text = $result['data']['message']['content'] ?? '';

        if ($this->isMalformedToolCallJson($text)) {
            $this->logger->warning('Ollama emitted malformed tool-call-shaped JSON in content instead of structured tool_calls.', ['content' => $text]);

            return [
                'type' => 'text',
                'message' => "Je n'ai pas bien compris votre demande, pouvez-vous reformuler ?",
                ...($cartMutated ? ['cartUpdated' => true] : []),
                ...(null !== $link ? ['link' => $link] : []),
            ];
        }

        return [
            'type' => 'text',
            'message' => $text !== '' ? $text : "Je n'ai pas compris votre demande, pouvez-vous reformuler ?",
            ...($cartMutated ? ['cartUpdated' => true] : []),
            ...(null !== $link ? ['link' => $link] : []),
        ];
    }

    /**
     * Normalizes a category argument to mean "no category filter" when it
     * clearly isn't a real category name — llama3.2 sometimes sends "" or
     * the literal string "null" instead of omitting the key entirely
     * (confirmed reproducible). Without this, either value would reach
     * resolveCategorySlug(), which naturally finds no match for "null", and
     * the caller's "explicit but invalid category" guard would then cancel
     * the whole search — silently discarding an otherwise valid
     * keyword/max_price filter along with it. keyword needs the same
     * normalization for the same reason — see normalizeKeywordArg(), added
     * once a plain truthy check in ProductRepository::search() (the
     * previous assumption here) was confirmed live to NOT actually catch
     * "null" as a string, since a non-empty string is always truthy in PHP.
     */
    private function normalizeCategoryArg(mixed $category): ?string
    {
        if (!is_string($category)) {
            return null;
        }

        $trimmed = trim($category);

        if ('' === $trimmed || 'null' === strtolower($trimmed)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Normalizes search_products' keyword argument the same way
     * normalizeCategoryArg()/normalizeMaxPriceArg() already do for theirs —
     * treating "" and the literal string "null" as "no keyword filter".
     *
     * Fixes a real, confirmed bug (not model variance): keyword was
     * previously passed straight through to ProductRepository::search(),
     * which only skips the `LIKE` filter via `if ($keyword)` — a plain
     * truthy check. That check does correctly skip an empty string, but
     * llama3.2 was observed sending the literal string "null" instead
     * (e.g. alongside category:"null", max_price:"60" for "je cherche des
     * produits à moins de 60 MAD") — a non-empty string, so it passed the
     * truthy check and became a real `LIKE '%null%'` filter, silently
     * excluding every product and wrongly reporting no results even though
     * max_price alone would have matched some. category and max_price
     * already had explicit "null"-string tolerance; keyword did not, which
     * is what this fixes.
     */
    private function normalizeKeywordArg(mixed $keyword): ?string
    {
        if (!is_string($keyword)) {
            return null;
        }

        $trimmed = trim($keyword);

        if ('' === $trimmed || 'null' === strtolower($trimmed)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Same tolerance as normalizeCategoryArg(), for the same reason: found
     * live while retesting the category fix, llama3.2 sends the same kind
     * of placeholder garbage for max_price too when it doesn't actually want
     * a price constraint — the literal string "null", or 0 — which
     * previously became (float) "null" === 0.0 (PHP's non-numeric-string
     * cast), silently filtering out every real product (none costs 0 MAD).
     * A genuine 0-or-negative max_price isn't a meaningful constraint either
     * way, so it's treated the same as "no price filter" regardless of how
     * it arrived.
     */
    private function normalizeMaxPriceArg(mixed $maxPrice): ?float
    {
        if (is_string($maxPrice)) {
            $trimmed = trim($maxPrice);

            if ('' === $trimmed || 'null' === strtolower($trimmed) || !is_numeric($trimmed)) {
                return null;
            }

            $maxPrice = $trimmed;
        }

        if (!is_numeric($maxPrice)) {
            return null;
        }

        $maxPrice = (float) $maxPrice;

        return $maxPrice > 0 ? $maxPrice : null;
    }

    /**
     * Normalizes search_products' sort argument to one of the two values
     * ProductRepository::search() understands, or null for "no sort
     * requested" (including any value that isn't one of the tool schema's
     * declared enum entries — same defensive tolerance as
     * normalizeCategoryArg()/normalizeMaxPriceArg() for whatever garbage
     * llama3.2 might otherwise send here).
     *
     * Exists to fix a real observed failure: asked for "the cheapest"/"le
     * moins cher", the model would guess an arbitrary low max_price (e.g.
     * 10 MAD) instead — sometimes low enough to silently exclude every real
     * product and wrongly report no results, confirmed reproducible with
     * both "Give me cheapest clothes" (correct spelling) and the French
     * "le moins cher", so this wasn't an English-spelling issue but a
     * missing capability: search_products had no way to express "sort by
     * price" at all. See the tool schema's `sort` parameter description and
     * SYSTEM_INSTRUCTION for the model-facing side of this fix.
     */
    private function normalizeSortArg(mixed $sort): ?string
    {
        if (!is_string($sort)) {
            return null;
        }

        return match (strtolower(trim($sort))) {
            'cheapest' => 'price_asc',
            'most_expensive' => 'price_desc',
            default => null,
        };
    }

    /**
     * Detects whether $keyword looks like a precise, specific product-name
     * reference — several words, and already matching at least one real
     * product by itself — as opposed to a generic descriptive search term
     * ("écouteurs", "vêtements").
     *
     * Exists because search_products' category argument has been repeatedly
     * observed hallucinated with no real basis, specifically when the model
     * is asked to look up one particular product by close to its exact
     * name — confirmed 3 separate times, e.g. "Facilis Non Cupiditate"
     * (really in Electronics) paired with a wrong `category: "Books"`,
     * which silently excluded the only product that would otherwise have
     * matched cleanly on keyword alone. A multi-word keyword precise enough
     * to already resolve on its own is a more reliable signal than category
     * in that situation. A short, generic keyword gives no such signal —
     * category there is still useful and stays respected, which is why this
     * requires several words rather than overriding category whenever any
     * product happens to match the keyword.
     */
    private function looksLikeSpecificProductName(string $keyword): bool
    {
        $wordCount = count(preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY));

        if ($wordCount < 2) {
            return false;
        }

        return [] !== $this->productRepository->search(null, $keyword);
    }

    private function resolveCategorySlug(?string $category): ?string
    {
        if (!$category) {
            return null;
        }

        foreach ($this->categoryRepository->findAll() as $categoryEntity) {
            if (0 === strcasecmp($categoryEntity->getSlug(), $category)
                || 0 === strcasecmp($categoryEntity->getName(), $category)) {
                return $categoryEntity->getSlug();
            }
        }

        return null;
    }
}
