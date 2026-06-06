import $ from 'jquery';

function setupAccountConfirmationWizard() {
    $('[data-account-confirmation-wizard]').each(function() {
        const $wizard = $(this);
        const roleDetailsRequired = String($wizard.data('role-details-required') || '')
            .split(',')
            .filter(Boolean);
        const $roleInput = $wizard.find('[data-wizard-role]');
        const $steps = $wizard.find('[data-wizard-step]');
        const $back = $wizard.find('[data-wizard-back]');
        const $next = $wizard.find('[data-wizard-next]');
        const $submit = $wizard.find('[data-wizard-submit]');
        const $current = $wizard.find('[data-wizard-progress-current]');
        const $total = $wizard.find('[data-wizard-progress-total]');
        let currentIndex = 0;

        function selectedRole() {
            const existingRole = $wizard.find('[data-role-dependent]').data('existing-role');

            return existingRole || $roleInput.val() || '';
        }

        function roleRequiresDetails() {
            return roleDetailsRequired.includes(selectedRole());
        }

        function syncRoleRequiredFields() {
            const required = roleRequiresDetails();

            $wizard.find('[data-role-required-field]').prop('required', required);
        }

        function visibleSteps() {
            syncRoleRequiredFields();

            return $steps.filter(function() {
                const $step = $(this);

                if ($step.is('[data-role-dependent]')) {
                    return roleRequiresDetails();
                }

                return true;
            });
        }

        function showStep(index) {
            const $visibleSteps = visibleSteps();
            const total = $visibleSteps.length;

            if (total === 0) {
                return;
            }

            currentIndex = Math.max(0, Math.min(index, total - 1));
            $steps.attr('hidden', true);

            const $step = $visibleSteps.eq(currentIndex);
            $step.removeAttr('hidden');

            $current.text(currentIndex + 1);
            $total.text(total);
            $back.prop('disabled', currentIndex === 0);
            $next.prop('hidden', currentIndex === total - 1);
            $submit.prop('hidden', currentIndex !== total - 1);
        }

        function currentStepIsValid() {
            const $step = visibleSteps().eq(currentIndex);
            const fields = $step.find('input, select, textarea').toArray();

            for (const field of fields) {
                if (!field.checkValidity()) {
                    field.reportValidity();
                    return false;
                }
            }

            return true;
        }

        $roleInput.on('change', function() {
            showStep(currentIndex);
        });

        $back.on('click', function() {
            showStep(currentIndex - 1);
        });

        $next.on('click', function() {
            if (currentStepIsValid()) {
                showStep(currentIndex + 1);
            }
        });

        $wizard.on('submit', function(event) {
            if (!currentStepIsValid()) {
                event.preventDefault();
            }
        });

        showStep(0);
    });
}

$(setupAccountConfirmationWizard);
