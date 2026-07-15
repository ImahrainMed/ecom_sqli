import { Controller } from '@hotwired/stimulus';

/*
 * Toggles the mini-cart dropdown on click and closes it on an outside
 * click. CSS :hover isn't used here: the gap between the toggle button
 * and the menu (top: calc(100% + 12px)) closes the menu before the
 * mouse reaches it.
 */
export default class extends Controller {
    connect() {
        this.closeOnClickOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.element.classList.remove('is-open');
            }
        };
        document.addEventListener('click', this.closeOnClickOutside);
    }

    disconnect() {
        document.removeEventListener('click', this.closeOnClickOutside);
    }

    toggle(event) {
        event.stopPropagation();
        this.element.classList.toggle('is-open');
    }
}
