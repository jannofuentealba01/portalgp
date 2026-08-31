<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpMontoClpFormatValue')) {
    function gpMontoClpFormatValue(string $value, int $decimals = 2): string
    {
        $raw = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', trim($value));
        $raw = is_string($raw) ? $raw : '';
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $raw) === 1) {
            $raw = str_replace('.', '', $raw);
        }

        if (!is_numeric($raw)) {
            return $value;
        }

        return number_format((float) $raw, $decimals, ',', '.');
    }
}

if (!function_exists('gpRenderMontoClpAssets')) {
    function gpRenderMontoClpAssets(): void
    {
        static $assetsRendered = false;
        if ($assetsRendered) {
            return;
        }
        $assetsRendered = true;
        ?>
        <style>
        .gp-monto-label {
            margin-bottom: .25rem;
            font-size: .78rem;
            font-weight: 700;
            color: #15803d;
        }

        .gp-monto-group .input-group-text {
            background: #f0fdf4;
            border-color: #16a34a;
            color: #15803d;
            font-weight: 700;
        }

        .gp-monto-input {
            font-weight: 700;
            border-color: #16a34a;
            color: #15803d;
        }
        </style>
        <script>
        (() => {
            const bind = () => {
                document.querySelectorAll('.js-gp-monto-clp').forEach((input) => {
                    if (!(input instanceof HTMLInputElement) || input.dataset.montoClpBound === '1') return;
                    input.dataset.montoClpBound = '1';

                    input.addEventListener('input', () => {
                        const oldValue = input.value;
                        const selectionStart = input.selectionStart ?? oldValue.length;
                        const charsBeforeCursor = oldValue.slice(0, selectionStart);
                        const sanitize = (value) => {
                            let out = value.replace(/[^\d,]/g, '');
                            const commaIndex = out.indexOf(',');
                            if (commaIndex !== -1) {
                                const decimals = out.slice(commaIndex + 1).replace(/,/g, '').slice(0, 2);
                                out = out.slice(0, commaIndex + 1) + decimals;
                            }
                            return out;
                        };
                        const raw = sanitize(oldValue);
                        const rawBeforeCursor = sanitize(charsBeforeCursor);
                        if (raw === oldValue) return;
                        input.value = raw;
                        const newPos = Math.min(rawBeforeCursor.length, raw.length);
                        input.setSelectionRange(newPos, newPos);
                    });

                    input.addEventListener('blur', () => {
                        const raw = input.value.trim();
                        if (raw === '') return;
                        const normalized = raw.replace(/\./g, '').replace(',', '.');
                        const n = Number.parseFloat(normalized);
                        if (!Number.isFinite(n)) return;
                        input.value = n.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    });
                });
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
            else bind();
        })();
        </script>
        <?php
    }
}

if (!function_exists('gpRenderMontoClpField')) {
    /**
     * @param array{
     *   wrapper_class?: string,
     *   id?: string,
     *   name?: string,
     *   label?: string,
     *   value?: string,
     *   placeholder?: string,
     *   required?: bool,
     *   hint?: string,
     *   autocomplete?: string,
     *   format_initial?: bool,
     *   decimals?: int
     * } $options
     */
    function gpRenderMontoClpField(array $options = []): void
    {
        gpRenderMontoClpAssets();

        $wrapperClass = trim((string) ($options['wrapper_class'] ?? 'col-12 col-lg-3'));
        $id = trim((string) ($options['id'] ?? 'monto_input'));
        $name = trim((string) ($options['name'] ?? 'monto'));
        $label = trim((string) ($options['label'] ?? 'Monto'));
        $value = (string) ($options['value'] ?? '');
        $placeholder = trim((string) ($options['placeholder'] ?? '0,00'));
        $required = (bool) ($options['required'] ?? true);
        $hint = trim((string) ($options['hint'] ?? ''));
        $autocomplete = trim((string) ($options['autocomplete'] ?? 'off'));
        $formatInitial = (bool) ($options['format_initial'] ?? true);
        $decimals = max(0, min(6, (int) ($options['decimals'] ?? 2)));
        $renderValue = $formatInitial ? gpMontoClpFormatValue($value, $decimals) : $value;
        ?>
        <div class="<?php echo gpComponentEscape($wrapperClass); ?>">
            <label class="form-label gp-monto-label" for="<?php echo gpComponentEscape($id); ?>">
                <i class="bi bi-cash-coin me-1" aria-hidden="true"></i><?php echo gpComponentEscape($label); ?>
            </label>
            <div class="input-group gp-monto-group">
                <span class="input-group-text">$</span>
                <input
                    type="text"
                    class="form-control gp-monto-input js-gp-monto-clp"
                    id="<?php echo gpComponentEscape($id); ?>"
                    name="<?php echo gpComponentEscape($name); ?>"
                    value="<?php echo gpComponentEscape($renderValue); ?>"
                    placeholder="<?php echo gpComponentEscape($placeholder); ?>"
                    inputmode="decimal"
                    autocomplete="<?php echo gpComponentEscape($autocomplete); ?>"
                    <?php echo $required ? 'required' : ''; ?>>
            </div>
            <?php if ($hint !== ''): ?>
                <div class="form-text"><?php echo gpComponentEscape($hint); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }
}
