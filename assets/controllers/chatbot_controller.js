import { Controller } from '@hotwired/stimulus';

/*
 * Floating chat widget. Toggles the panel like cart_dropdown_controller,
 * and posts messages to /chatbot/message, rendering a plain text reply, a
 * list of clickable product cards, or a cart summary depending on the
 * response "type". Backed by a local Ollama model — a cold response (first
 * call after the prompt cache is empty) can take up to ~3 minutes, hence the
 * typing indicator staying up for longer than a hosted API would need.
 *
 * History is persisted server-side (session), not in the DOM/JS state: on
 * every connect (including after a full page navigation, e.g. clicking a
 * product card), it's re-fetched from /chatbot/history and re-rendered, and
 * the panel reopens automatically if that history isn't empty.
 */
export default class extends Controller {
    static targets = ['panel', 'messages', 'input', 'typing'];

    connect() {
        this.defaultGreetingHTML = this.messagesTarget.innerHTML;

        this.closeOnClickOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.element.classList.remove('is-open');
            }
        };
        document.addEventListener('click', this.closeOnClickOutside);

        this.loadHistory();
    }

    disconnect() {
        document.removeEventListener('click', this.closeOnClickOutside);
    }

    toggle(event) {
        event.stopPropagation();
        this.element.classList.toggle('is-open');

        if (this.element.classList.contains('is-open')) {
            this.inputTarget.focus();
        }
    }

    async send(event) {
        event.preventDefault();

        const message = this.inputTarget.value.trim();
        if (!message) {
            return;
        }

        this.appendMessage('user', message);
        this.inputTarget.value = '';
        this.inputTarget.disabled = true;
        this.typingTarget.hidden = false;
        this.scrollToBottom();

        try {
            const response = await fetch('/chatbot/message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message }),
            });

            const data = await response.json();
            this.renderBotResponse(data);

            if (data.cartUpdated) {
                document.dispatchEvent(new CustomEvent('cart:updated'));
            }
        } catch (error) {
            this.appendMessage('bot', 'Désolé, une erreur est survenue. Réessayez plus tard.');
        } finally {
            this.typingTarget.hidden = true;
            this.inputTarget.disabled = false;
            this.inputTarget.focus();
            this.scrollToBottom();
        }
    }

    async clear(event) {
        event.stopPropagation();

        try {
            await fetch('/chatbot/clear', { method: 'POST' });
        } catch (error) {
            // Reset the visible panel regardless of whether the request succeeded.
        }

        this.messagesTarget.innerHTML = this.defaultGreetingHTML;
        this.scrollToBottom();
    }

    async loadHistory() {
        try {
            const response = await fetch('/chatbot/history');
            const data = await response.json();
            const messages = Array.isArray(data.messages) ? data.messages : [];

            if (messages.length === 0) {
                return;
            }

            this.messagesTarget.innerHTML = '';
            messages.forEach((entry) => this.renderHistoryEntry(entry));
            this.element.classList.add('is-open');
            this.scrollToBottom();
        } catch (error) {
            // Keep the default greeting bubble if history can't be loaded.
        }
    }

    renderHistoryEntry(entry) {
        if (entry.role === 'user') {
            this.appendMessage('user', entry.message);
            return;
        }

        this.renderBotResponse(entry);
    }

    renderBotResponse(data) {
        if (data.type === 'products' && Array.isArray(data.products) && data.products.length > 0) {
            this.appendMessage('bot', data.message);
            this.appendProducts(data.products);
        } else if (data.type === 'cart' && Array.isArray(data.items) && data.items.length > 0) {
            this.appendMessage('bot', data.message);
            this.appendCartSummary(data.items, data.total);
        } else {
            this.appendMessage('bot', data.message || "Je n'ai pas compris votre demande.");
        }

        if (typeof data.link === 'string' && data.link) {
            this.appendLink(data.link);
        }
    }

    appendMessage(role, text) {
        const wrapper = document.createElement('div');
        wrapper.className = `chatbot-message chatbot-message--${role}`;

        const bubble = document.createElement('div');
        bubble.className = 'chatbot-message__bubble';
        bubble.textContent = text;

        wrapper.appendChild(bubble);
        this.messagesTarget.appendChild(wrapper);
        this.scrollToBottom();
    }

    appendProducts(products) {
        const wrapper = document.createElement('div');
        wrapper.className = 'chatbot-message chatbot-message--bot';

        const list = document.createElement('div');
        list.className = 'chatbot-products';

        products.forEach((product) => {
            const card = document.createElement('a');
            card.className = 'chatbot-product-card';
            card.href = product.url;

            const main = document.createElement('div');
            main.className = 'chatbot-product-card__main';

            const name = document.createElement('span');
            name.className = 'chatbot-product-card__name';
            name.textContent = product.name;

            const price = document.createElement('span');
            price.className = 'chatbot-product-card__price';
            price.textContent = `${product.price.toFixed(2)} MAD`;

            main.append(name, price);

            const stock = document.createElement('span');
            const inStock = product.in_stock !== false;
            stock.className = `chatbot-product-card__stock chatbot-product-card__stock--${inStock ? 'in' : 'out'}`;
            stock.textContent = inStock ? 'En stock' : 'Rupture';

            card.append(main, stock);
            list.appendChild(card);
        });

        wrapper.appendChild(list);
        this.messagesTarget.appendChild(wrapper);
        this.scrollToBottom();
    }

    // Renders the cart's exact quantities/subtotals/total straight from the
    // backend response — see ChatbotService::handleGetCartContents(), which
    // deliberately never lets the model restate these numbers itself.
    appendCartSummary(items, total) {
        const wrapper = document.createElement('div');
        wrapper.className = 'chatbot-message chatbot-message--bot';

        const list = document.createElement('div');
        list.className = 'chatbot-cart-summary';

        items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'chatbot-cart-summary__item';

            const name = document.createElement('span');
            name.className = 'chatbot-cart-summary__name';
            name.textContent = `${item.quantity}x ${item.name}`;

            const subtotal = document.createElement('span');
            subtotal.className = 'chatbot-cart-summary__subtotal';
            subtotal.textContent = `${Number(item.subtotal).toFixed(2)} MAD`;

            row.append(name, subtotal);
            list.appendChild(row);
        });

        if (typeof total === 'number') {
            const totalRow = document.createElement('div');
            totalRow.className = 'chatbot-cart-summary__total';
            totalRow.textContent = `Total : ${total.toFixed(2)} MAD`;
            list.appendChild(totalRow);
        }

        wrapper.appendChild(list);
        this.messagesTarget.appendChild(wrapper);
        this.scrollToBottom();
    }

    appendLink(url) {
        const wrapper = document.createElement('div');
        wrapper.className = 'chatbot-message chatbot-message--bot';

        // Built from a backend-resolved URL via a real <a href>, the same
        // safe pattern as appendProducts() — never from raw HTML/innerHTML,
        // so nothing the model writes in its text can smuggle in a fake link.
        const link = document.createElement('a');
        link.className = 'chatbot-link-button';
        link.href = url;
        link.textContent = 'Ouvrir la page →';

        wrapper.appendChild(link);
        this.messagesTarget.appendChild(wrapper);
        this.scrollToBottom();
    }

    scrollToBottom() {
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }
}
