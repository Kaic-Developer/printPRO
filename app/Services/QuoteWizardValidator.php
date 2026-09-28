<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class QuoteWizardValidator
{
    /** Valida apenas os campos descritos pelo preset do servidor, nunca um schema enviado pelo cliente. */
    public function validate(array $schema, mixed $answers): array
    {
        if (! is_array($answers)) {
            throw ValidationException::withMessages(['answers' => 'Preencha os detalhes do produto.']);
        }

        $rules = [];
        $allowed = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $key = $field['key'];
            $allowed[] = $key;
            $value = $answers[$key] ?? null;
            $visible = $this->visible($field, $answers);
            if (! $visible) {
                continue;
            }
            // A condicao controla somente a exibicao; o schema decide se a resposta e obrigatoria.
            $required = ($field['required'] ?? false) ? 'required' : 'nullable';
            $rules[$key] = match ($field['type']) {
                'boolean' => [$required, 'boolean'],
                'integer' => [$required, 'integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 1000000)],
                'decimal' => [$required, 'regex:/\A\d{1,7}(?:[.,]\d{1,3})?\z/'],
                'text' => [$required, 'string', 'max:1000'],
                'select' => [$required, 'string', 'in:'.implode(',', array_column($field['options'] ?? [], 'value'))],
                'multiselect' => [$required, 'array'],
                'size_grid' => [$required, 'array'],
                default => throw ValidationException::withMessages(['answers' => 'O modelo deste produto contém um campo não suportado.']),
            };
        }

        $filtered = array_intersect_key($answers, array_flip($allowed));
        $validator = Validator::make($filtered, $rules, [
            '*.required' => 'Este detalhe é obrigatório para concluir o orçamento.',
            '*.in' => 'Selecione uma opção disponível.',
            '*.regex' => 'Use somente números e até três casas decimais.',
        ]);
        $validator->after(function ($validator) use ($schema, $filtered): void {
            foreach ($schema['fields'] ?? [] as $field) {
                if (! $this->visible($field, $filtered) || ! array_key_exists($field['key'], $filtered) || $filtered[$field['key']] === null || $filtered[$field['key']] === '') {
                    continue;
                }
                $value = $filtered[$field['key']];
                if ($field['type'] === 'multiselect') {
                    $options = array_column($field['options'] ?? [], 'value');
                    if (count(array_diff((array) $value, $options)) > 0) {
                        $validator->errors()->add($field['key'], 'Há uma opção de acabamento inválida.');
                    }
                }
                if ($field['type'] === 'size_grid') {
                    $sizes = array_column($field['sizes'] ?? [], 'value');
                    if (count(array_diff(array_keys((array) $value), $sizes)) > 0 || count(array_filter((array) $value, fn ($count) => filter_var($count, FILTER_VALIDATE_INT) === false || (int) $count < 0 || (int) $count > 100000)) > 0 || array_sum(array_map('intval', (array) $value)) < 1) {
                        $validator->errors()->add($field['key'], 'Informe quantidades válidas para a grade de tamanhos.');
                    }
                }
                if (in_array($field['type'], ['integer', 'decimal'], true) && preg_match('/\A\d+(?:[.,]\d{1,3})?\z/', (string) $value)) {
                    $actual = $this->decimalMilli((string) $value);
                    $minimum = isset($field['min']) ? $this->decimalMilli((string) $field['min']) : null;
                    $maximum = isset($field['max']) ? $this->decimalMilli((string) $field['max']) : null;
                    if (($minimum !== null && $actual < $minimum) || ($maximum !== null && $actual > $maximum)) {
                        $validator->errors()->add($field['key'], 'O valor está fora do intervalo permitido.');
                    }
                }
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Campos invisíveis não entram no snapshot, para evitar reaproveitar uma resposta condicional obsoleta.
        return collect($filtered)->filter(function ($value, $key) use ($schema, $filtered): bool {
            $field = collect($schema['fields'] ?? [])->firstWhere('key', $key);
            return $field !== null && $this->visible($field, $filtered);
        })->all();
    }

    private function visible(array $field, array $answers): bool
    {
        $condition = $field['visible_when'] ?? null;
        if ($condition === null) {
            return true;
        }
        $actual = $answers[$condition['field']] ?? null;
        if (isset($condition['in'])) {
            return in_array((string) $actual, array_map('strval', $condition['in']), true);
        }
        $expected = $condition['equals'] ?? null;
        if (is_bool($expected)) {
            $expected = $expected ? '1' : '0';
        }
        return (string) $actual === (string) $expected;
    }

    private function decimalMilli(string $value): int
    {
        [$whole, $fraction] = array_pad(preg_split('/[.,]/', $value, 2), 2, '0');
        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }
}
