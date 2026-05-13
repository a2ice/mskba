import $ from 'jquery';
import * as forms from '../../core/forms.js';

const CLASSIC_MODAL = 'auth-entry-classic';
const DEFAULT_SECTION = 'login';

$(document).on('click', '[data-auth-classic-link]', function(event) {
    event.preventDefault();

    const trigger = $(this);
    const modal = trigger.closest('[data-modal]');
    const target = String(trigger.data('authClassicTarget') || trigger.data('auth-classic-target') || '').trim();

    if (!modal.length || !target) {
        return;
    }

    resetClassicFormStates(modal);
    activateSection(modal, target);
});

$(document).on('submit', '[data-auth-classic-form]', function(event) {
    event.preventDefault();

    const form = $(this);

    forms.submitForm(form, {
        onSuccess(response) {
            console.log('Login successful');
            console.log(response);
            location.reload();
        },
        onError(jqXHR) {
            console.log('Login failed');
            console.log(jqXHR.responseJSON);
        }
    });

});

$(document).on('modal:opened', function(_event, modal) {
    if (!isClassicAuthModal(modal)) {
        return;
    }

    resetClassicModalState(modal);
    activateSection(modal, DEFAULT_SECTION);
});

$(document).on('modal:closed', function(_event, modal) {
    if (!isClassicAuthModal(modal)) {
        return;
    }

    resetClassicModalState(modal);
});

function isClassicAuthModal(modal) {
    return modal.data('modal') === CLASSIC_MODAL;
}

function resetClassicModalState(modal) {
    modal.find('[data-auth-classic-form]').each(function() {
        this.reset();
        forms.resetFormState(this);
    });
}

function resetClassicFormStates(modal) {
    modal.find('[data-auth-classic-form]').each(function() {
        forms.resetFormState(this);
    });
}

function activateSection(modal, target) {
    const sections = modal.find('[data-auth-classic-section]');
    const targetSection = modal.find('[data-auth-classic-section="' + target + '"]');

    if (!targetSection.length) {
        return;
    }

    sections.attr('hidden', true);
    targetSection.removeAttr('hidden');
}
