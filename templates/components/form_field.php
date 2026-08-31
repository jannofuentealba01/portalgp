<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpRenderFieldLabel')) {
    function gpRenderFieldLabel(string $text, bool $required = false, string $optionalText = 'opcional', string $for = ''): void
    {
        $forAttr = trim($for) !== '' ? ' for="' . gpComponentEscape($for) . '"' : '';
        ?>
        <label class="form-label gp-field-label"<?php echo $forAttr; ?>>
            <span><?php echo gpComponentEscape($text); ?></span>
            <?php if ($required): ?>
                <span class="text-danger fw-bold" aria-hidden="true">*</span>
                <span class="visually-hidden">(Obligatorio)</span>
            <?php else: ?>
                <span class="text-muted fw-normal">(<?php echo gpComponentEscape($optionalText); ?>)</span>
            <?php endif; ?>
        </label>
        <?php
    }
}

if (!function_exists('gpRenderFieldHint')) {
    function gpRenderFieldHint(string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        echo '<div class="form-text">' . gpComponentEscape($text) . '</div>';
    }
}

if (!function_exists('gpRenderFieldError')) {
    function gpRenderFieldError(string $id, string $text, bool $hidden = true): void
    {
        if (trim($id) === '' || trim($text) === '') {
            return;
        }

        $class = $hidden ? 'invalid-feedback d-block d-none' : 'invalid-feedback d-block';
        echo '<div id="' . gpComponentEscape($id) . '" class="' . gpComponentEscape($class) . '">' . gpComponentEscape($text) . '</div>';
    }
}
