// Default options
const defaults = {
    selector: '.innomedio-formbuilder',
    scrollOffset: 150,
    addFormSentListener: true
};

/**
 * Merge two or more objects
 */
function extend() {
    let extended = {};
    let deep = false;
    let i = 0;
    let length = arguments.length;
    if (Object.prototype.toString.call(arguments[0]) === '[object Boolean]') {
        deep = arguments[0];
        i++
    }
    let merge = (obj) => {
        for (let prop in obj) {
            if (Object.prototype.hasOwnProperty.call(obj, prop)) {
                if (deep && Object.prototype.toString.call(obj[prop]) === '[object Object]') {
                    extended[prop] = extend(true, extended[prop], obj[prop])
                } else {
                    extended[prop] = obj[prop]
                }
            }
        }
    };
    for (; i < length; i++) {
        let obj = arguments[i];
        merge(obj)
    }
    return extended
}

/**
 * Equivalent to $('.parent').find('.foo').removeClass('.foo');
 *
 * @param parent
 * @param className
 */
function removeClass(parent, className) {
    const elements = parent.querySelectorAll('.' + className);
    for (var i = 0; i < elements.length; i++) {
        elements[i].classList.remove(className);
    }
}

/**
 * Equivalent to $('.parent').find(selector).empty();
 *
 * @param parent
 * @param selector
 */
function makeEmpty(parent, selector) {
    const elements = parent.querySelectorAll(selector);
    for (var i = 0; i < elements.length; i++) {
        elements[i].innerHTML = '';
    }
}

class InnomedioForm {
    constructor(options) {
        this.options = extend(defaults, options || {});
    }

    init() {
        this.addFormSentListener();
        this.addFormSubmitHandler();
    }

    resetGrecaptchas() {
        if(typeof grecaptcha === 'undefined') {
            return false;
        }

        var formsWithGrecaptcha = document.querySelectorAll(this.options.selector + '.has-grecaptcha');

        for(var i = 0; i < formsWithGrecaptcha.length; i++) {
            grecaptcha.reset(i);
        }
    };

    addFormSubmitHandler() {
        const self = this;
        const forms = document.querySelectorAll(this.options.selector);

        for (var i = 0; i < forms.length; i++) {
            forms[i].addEventListener('submit', function (e) {
                // Polyfill
                if (!Element.prototype.matches) {
                    Element.prototype.matches = Element.prototype.msMatchesSelector || Element.prototype.webkitMatchesSelector;
                }

                if (e.target.matches(self.options.selector)) {
                    self.handleFormSubmit(e);
                }
            });
        }
    }

    async handleFormSubmit(e) {
        e.preventDefault();

        const self = this;
        const form = e.target;

        if (!form.classList.contains('is-busy')) {
            form.classList.add('is-busy');

            // The token can only be requested when the sitekey is available.
            const siteKeyEl = document.getElementById('captcha_sitekey');

            if(siteKeyEl && siteKeyEl.value) {
                await this.requestRecaptchaToken(siteKeyEl.value);
            }

            form.classList.remove('was-sent');
            form.querySelector("input[type='submit'],button").classList.add('is-loading');
            removeClass(form, 'is-invalid');
            makeEmpty(form, '.error-field');
            makeEmpty(form, '.invalid-feedback');
            makeEmpty(form, '.form-success-message');

            const formData = new FormData(form);

            var xhr = new XMLHttpRequest();

            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        self.handleFormSubmitSuccess(form, xhr.response);
                    } else {
                        self.handleFormSubmitError(form);
                    }
                }
            };

            xhr.open(form.method, form.action);
            xhr.send(formData);
        }

        return false;
    }

    handleFormSubmitSuccess(form, response) {
        response = JSON.parse(response);

        if (form.classList.contains('has-grecaptcha')) {
            this.resetGrecaptchas();
        }

        if (response.errors && Object.keys(response.errors).length > 0) {
            for (var fieldId in response.errors) {
                var input = form.querySelector('#' + fieldId);
                var errorContainer;

                if (! input) {
                    var inputs = form.querySelectorAll("[id^=" + fieldId + "]");
                    input = inputs[inputs.length - 1];

                    var lastCheckbox = input
                        .parentElement
                        .parentElement
                        .querySelector('.custom-checkbox:last-child');

                    if(! lastCheckbox) {
                        lastCheckbox = input
                            .parentElement
                            .parentElement
                            .querySelector('.form-check:last-child');
                    }

                    if(lastCheckbox) {
                        errorContainer = lastCheckbox.querySelector('.error-field');
                    }

                    inputs.forEach(function(input) {
                        input.classList.add('is-invalid');
                    });
                } else {
                    errorContainer = input.parentElement.querySelector('.error-field');
                    input.classList.add('is-invalid');
                }

                if(errorContainer) {
                    errorContainer.innerHTML = response.errors[fieldId];
                }
            }
        }

        if (response.success === true) {
            form.querySelector('.form-success-message').innerHTML = response.message;
            form.querySelector('.form-success-message').classList.remove('d-none');
            form.classList.add('was-sent');
            this.dispatchFormSentEvent(form, 'success');

            if (typeof gtag !== "undefined") {
                gtag('event', 'form_submit_success', {
                    'event_category': 'form',
                    'event_label': label
                });
            } else if (typeof ga !== "undefined") {
                var prefix = ga.getAll().map(t => t.get('name'));
                ga(prefix + '.send', 'event', 'form', 'form_submit_success', label);
            }
        } else if (response.message) {
            form.querySelector('.invalid-feedback').innerHTML = response.message;
            this.dispatchFormSentEvent(form, 'error');
        }

        form.classList.remove('is-busy');
        form.querySelector("input[type='submit'],button").classList.remove('is-loading');

        var label = form.getAttribute('data-url');
        var tag = form.getAttribute('data-form-tag');

        if(tag === 'newsletter') {
            label = tag;
        }
    }

    handleFormSubmitError(form) {
        if (form.classList.contains('has-grecaptcha')) {
            this.resetGrecaptchas();
        }

        form.classList.remove('is-busy');
        form.querySelector("input[type='submit'],button").classList.remove('is-loading');

        if (typeof gtag !== "undefined") {
            gtag('event', 'form_submit_error', {
                'event_category': 'form',
                'event_label': currentUrl
            });
        } else if (typeof ga !== "undefined") {
            var prefix = ga.getAll().map(t => t.get('name'));
            ga(prefix + '.send', 'event', 'form', 'form_submit_error', form.getAttribute('url'));
        }
    }

    dispatchFormSentEvent(form) {
        var event = document.createEvent('Event');

        event.initEvent('sent', true, true);

        form.dispatchEvent(event);
    }

    addFormSentListener() {
        if(this.options.addFormSentListener) {
            const forms = document.querySelectorAll(this.options.selector);
            const self = this;

            for (var i = 0; i < forms.length; i++) {
                forms[i].addEventListener('sent', (e) => {
                    var bodyRect = document.body.getBoundingClientRect(),
                        elemRect = e.target.getBoundingClientRect(),
                        elemOffset   = elemRect.top - bodyRect.top;

                    window.scroll(0, elemOffset - self.options.scrollOffset);
                });
            }
        }
    }

    // Get the recaptcha token.
    requestRecaptchaToken(siteKey) {
        return new Promise((resolve, reject) => {
            grecaptcha.ready(function () {
                grecaptcha.execute(siteKey, {
                    action: 'form'
                }).then(function (token) {
                    //the token will be sent on form submit
                    document.getElementById("captcha").value = token;
                    resolve();
                });
            });
        });
    }
}

module.exports = (options = {}) => {
    const instance = new InnomedioForm(options);
    instance.init();

    return instance;
};
