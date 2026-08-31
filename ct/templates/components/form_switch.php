<?php
declare(strict_types=1);

if (!function_exists('ctFormSwitchEscape')) {
    function ctFormSwitchEscape(string $value): string
    {
        if (function_exists('ctEscape')) {
            return ctEscape($value);
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ctRenderFormSwitch')) {
    /**
     * @param array{
     *   id: string,
     *   name?: string,
     *   label: string,
     *   checked?: bool,
     *   wrapper_class?: string,
     *   help_text?: string
     * } $options
     */
    function ctRenderFormSwitch(array $options): void
    {
        $id = trim((string) ($options['id'] ?? ''));
        $label = trim((string) ($options['label'] ?? ''));
        if ($id === '' || $label === '') {
            return;
        }

        $name = trim((string) ($options['name'] ?? $id));
        $checked = (bool) ($options['checked'] ?? false);
        $wrapperClass = trim((string) ($options['wrapper_class'] ?? 'ct-switch mt-2'));
        $helpText = trim((string) ($options['help_text'] ?? ''));
        ?>
        <div class="<?php echo ctFormSwitchEscape($wrapperClass); ?>">
            <div class="form-check form-switch">
                <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    id="<?php echo ctFormSwitchEscape($id); ?>"
                    name="<?php echo ctFormSwitchEscape($name); ?>"
                    <?php echo $checked ? 'checked' : ''; ?>>
                <label class="form-check-label" for="<?php echo ctFormSwitchEscape($id); ?>">
                    <?php echo ctFormSwitchEscape($label); ?>
                </label>
            </div>
            <?php if ($helpText !== ''): ?>
                <div class="ct-help"><?php echo ctFormSwitchEscape($helpText); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }
}
