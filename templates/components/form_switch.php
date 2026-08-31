<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpRenderFormSwitch')) {
    /**
     * @param array{
     *   id: string,
     *   name?: string,
     *   label: string,
     *   checked?: bool,
     *   wrapper_class?: string,
     *   help_text?: string,
     *   value?: string,
     *   attrs?: array<string, mixed>
     * } $options
     */
    function gpRenderFormSwitch(array $options): void
    {
        $id = trim((string) ($options['id'] ?? ''));
        $label = trim((string) ($options['label'] ?? ''));
        if ($id === '' || $label === '') {
            return;
        }

        $name = trim((string) ($options['name'] ?? $id));
        $checked = (bool) ($options['checked'] ?? false);
        $wrapperClass = trim((string) ($options['wrapper_class'] ?? 'gp-form-switch'));
        $helpText = trim((string) ($options['help_text'] ?? ''));
        $value = trim((string) ($options['value'] ?? '1'));
        $attrs = is_array($options['attrs'] ?? null) ? $options['attrs'] : [];
        ?>
        <div class="<?php echo gpComponentEscape($wrapperClass); ?>">
            <div class="form-check form-switch">
                <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    id="<?php echo gpComponentEscape($id); ?>"
                    name="<?php echo gpComponentEscape($name); ?>"
                    value="<?php echo gpComponentEscape($value); ?>"
                    <?php echo $checked ? 'checked' : ''; ?>
                    <?php echo gpComponentAttrs($attrs); ?>>
                <label class="form-check-label" for="<?php echo gpComponentEscape($id); ?>">
                    <?php echo gpComponentEscape($label); ?>
                </label>
            </div>
            <?php if ($helpText !== ''): ?>
                <div class="form-text"><?php echo gpComponentEscape($helpText); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }
}
