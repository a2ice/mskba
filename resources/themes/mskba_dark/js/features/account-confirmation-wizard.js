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
        const existingRole = String($wizard.data('existing-role') || '');
        const existingRoleLabel = String($wizard.data('existing-role-label') || '');
        let currentIndex = 0;

        function selectedRole() {
            return existingRole || $roleInput.val() || '';
        }

        function selectedRoleLabel() {
            if (existingRoleLabel) {
                return existingRoleLabel;
            }

            return selectedOptionLabel($roleInput);
        }

        function selectedGenderLabel() {
            return selectedOptionLabel($wizard.find('[name="gender"]'));
        }

        function selectedOptionLabel($input) {
            const value = $input.val();

            if (!value) {
                return '';
            }

            return $input.find('option:selected').text().trim();
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
            syncStepSummary($step);
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

        function syncCurrentSummary() {
            const $step = visibleSteps().eq(currentIndex);

            if ($step.length) {
                syncStepSummary($step);
            }
        }

        function syncStepSummary($step) {
            const $summary = $step.find('[data-wizard-summary]');

            if (!$summary.length) {
                return;
            }

            const items = summaryItems($step.data('step-key'));

            if (items.length === 0) {
                $summary.attr('hidden', true).empty();
                return;
            }

            const $list = $('<dl>', { class: 'account-confirmation-step-summary__list' });

            for (const item of items) {
                $list
                    .append($('<dt>').text(item.label))
                    .append($('<dd>').text(item.value));
            }

            $summary.empty().append($list).removeAttr('hidden');
        }

        function summaryItems(stepKey) {
            const items = [];
            const roleLabel = selectedRoleLabel();

            if (roleLabel) {
                items.push({ label: 'Роль участия', value: roleLabel });
            }

            if (['gender', 'name'].includes(stepKey) && roleRequiresDetails()) {
                const birthDate = $wizard.find('[name="birth_date"]').val();

                if (birthDate) {
                    items.push({ label: 'Дата рождения', value: formatDate(birthDate) });
                }
            }

            if (stepKey === 'name' && roleRequiresDetails()) {
                const genderLabel = selectedGenderLabel();

                if (genderLabel) {
                    items.push({ label: 'Пол', value: genderLabel });
                }
            }

            return items;
        }

        function formatDate(value) {
            const parts = String(value).split('-');

            if (parts.length !== 3) {
                return value;
            }

            return `${parts[2]}.${parts[1]}.${parts[0]}`;
        }

        $roleInput.on('change', function() {
            showStep(currentIndex);
        });

        $wizard.on('input change', 'input, select, textarea', function() {
            syncCurrentSummary();
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
