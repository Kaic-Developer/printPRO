<?php

namespace App\Http\Controllers;

class ModuleController extends Controller
{
    public function index()
    {
        $modules = [
            ['name' => 'Clientes', 'status' => 'Disponível', 'description' => 'Cadastro, busca e organização dos clientes da gráfica.', 'icon' => 'users'],
            ['name' => 'Catálogo', 'status' => 'Disponível', 'description' => 'Produtos, unidades, preços de referência e situação do cadastro.', 'icon' => 'catalog'],
            ['name' => 'Financeiro', 'status' => 'Disponível', 'description' => 'Registro manual de entradas e saídas com resumo mensal.', 'icon' => 'chart'],
            ['name' => 'Orçamentos', 'status' => 'Próxima etapa', 'description' => 'Composição de itens, valores, validade e aprovação pelo cliente.', 'icon' => 'quote'],
            ['name' => 'Pedidos e produção', 'status' => 'Planejado', 'description' => 'Acompanhe cada trabalho da aprovação até a entrega.', 'icon' => 'orders'],
            ['name' => 'Atendimento', 'status' => 'Planejado', 'description' => 'Caixa de conversas para os vendedores da gráfica.', 'icon' => 'chat'],
            ['name' => 'Relatórios', 'status' => 'Planejado', 'description' => 'Indicadores integrados a clientes, orçamentos, pedidos e financeiro.', 'icon' => 'chart'],
            ['name' => 'Acesso pelo celular', 'status' => 'Planejado', 'description' => 'API autenticada e experiência móvel para a equipe.', 'icon' => 'mobile'],
        ];

        return view('modules.index', compact('modules'));
    }
}
