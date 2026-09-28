<?php

return [
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute deve ser um texto.',
    'email' => 'Informe um e-mail válido.',
    'unique' => 'Este :attribute já está cadastrado.',
    'confirmed' => 'A confirmação de :attribute não corresponde.',
    'in' => 'Selecione uma opção válida para :attribute.',
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'min' => ['string' => 'O campo :attribute deve ter pelo menos :min caracteres.'],
    'max' => ['string' => 'O campo :attribute deve ter no máximo :max caracteres.'],
    'attributes' => [
        'name' => 'nome', 'organization_name' => 'nome da gráfica', 'email' => 'e-mail',
        'password' => 'senha', 'type' => 'tipo de cliente', 'phone' => 'telefone',
        'document' => 'documento', 'notes' => 'observações', 'q' => 'busca',
    ],
];
