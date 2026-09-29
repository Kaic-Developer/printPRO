# Orçamentos inteligentes

O módulo separa o catálogo técnico global da configuração de cada gráfica. O catálogo-base fica em `database/seeders/data/quote-preset-catalog.php`; ele descreve categorias, materiais, processos, produtos e campos do wizard, sem sugerir preços, custos, perdas ou markup. `QuotePresetSeeder` sincroniza os presets globais e cria as opções de cada organização sem sobrescrever escolhas existentes. Cadastros novos recebem a configuração inicial pelo fluxo de criação da gráfica.

## Dados e isolamento

- `quote_presets` guarda códigos estáveis, hierarquia, unidade, setor, componentes sugeridos e `wizard_schema` versionado. O schema descreve campos, opções e condições de exibição; ele nunca é executado como código.
- `organization_quote_presets` controla ativação e custo unitário em centavos por organização. Campo de custo nulo significa desconhecido; custo zero só é aceito quando a gráfica o informa explicitamente.
- `organization_quote_settings` guarda perda em pontos-base e multiplicador de markup em pontos-base. Ambos começam nulos.
- `quotes` pertence à organização do usuário autenticado; cada `quote_versions` preserva um snapshot imutável de respostas, componentes, custos e fatores comerciais usados naquela proposta.
- `quote_items` e `quote_item_components` guardam linhas e ficha técnica com nomes/unidades copiados, para que renomear um preset não altere documentos antigos.
- `production_orders` copia as instruções relevantes de cada setor. A chave única `(quote_version_id, sector)` junto da transação torna a aprovação repetida idempotente.
- `personal_access_tokens` é usado pelo Laravel Sanctum para a API móvel.

Preços persistidos usam centavos inteiros. Quantidades aceitam até três casas decimais, guardadas em milésimos. O cálculo usa aritmética inteira com arredondamento half-up para centavos e rejeita estouro numérico. Uma linha sem custo unitário ou consumo informado pode ser salva como rascunho, mas o orçamento fica sem total vendável e não pode ser aprovado.

## Cálculo comercial

O total segue a regra solicitada, usando a soma do custo de todos os componentes selecionados:

```text
custo = Σ(arredondar(custo_unitário_centavos × consumo_milésimos / 1000))
custo_com_perda = arredondar(custo × (10000 + perda_bp) / 10000)
preço_final = arredondar(custo_com_perda × multiplicador_markup_bp / 10000)
```

O administrador precisa preencher os dois fatores. `perda_bp=500` representa perda de 5%; `markup_multiplier_bp=20000` representa multiplicador `2,00×`. Markup é multiplicador sobre custo, não margem bruta: por exemplo, `2,00×` sem perda corresponde a 50% de margem bruta antes das despesas operacionais. O sistema não afirma cobrir impostos, taxas ou despesas que não tenham sido modelados.

O arredondamento por componente ocorre na linha de custo, e o preço final do orçamento é calculado sobre o custo global. Um eventual centavo de diferença entre a soma de preços por linha e o total global é ajustado na última linha para que os totais fechem.

## API v1

A API usa JSON, prefixo `/api/v1` e tokens Bearer do Sanctum. Os tokens são limitados a `catalog:read`, `catalog:write`, `quotes:read`, `quotes:write` e `production:read`; o token é exibido apenas no retorno da emissão. `organization_id` nunca é aceito no corpo da requisição.

| Método e caminho | Escopo | Uso |
|---|---|---|
| `POST /auth/token` | público, limite 10/min | Emite token com `email`, `password`, `device_name` |
| `DELETE /auth/token` | autenticado | Revoga o token usado |
| `GET /quote-presets` | `catalog:read` | Taxonomia, presets habilitados, schemas, custos e fatores configurados |
| `GET /quote-settings` | `catalog:read` | Mesmo catálogo com custos e parâmetros da gráfica |
| `PATCH /quote-settings` | `catalog:write` | Atualiza `items[código][is_enabled,unit_cost]`, `waste_percentage`, `markup_multiplier` |
| `GET /quotes` | `quotes:read` | Lista paginada da organização |
| `POST /quotes` | `quotes:write` | Cria orçamento e versão 1 |
| `GET /quotes/{id}` | `quotes:read` | Consulta versão, itens e ordens de produção da organização |
| `POST /quotes/{id}/versions` | `quotes:write` | Cria nova versão enquanto o orçamento não estiver aprovado; exige `expected_version` para detectar edição concorrente |
| `POST /quotes/{id}/approve` | `quotes:write` | Aprova e gera uma O.S. por setor |
| `GET /production-orders` | `production:read` | Lista ordens paginadas, opcionalmente filtradas por setor/situação |
| `POST /nesting-estimates` | `quotes:write` | Estima aproveitamento de chapa ou bobina |

O corpo de criação contém uma lista de linhas. Códigos dos componentes devem pertencer às sugestões do preset selecionado; nenhum componente pode ser habilitado por um identificador de outra organização.

```json
{
  "customer_id": 18,
  "expires_at": "2026-10-15",
  "items": [
    {
      "preset_code": "uniform-polo",
      "answers": {
        "size_grid": {"P": 4, "M": 8, "G": 8, "GG": 5},
        "fabric": "piquet",
        "personalization": "silk-screen",
        "silk_front_colors": 2,
        "silk_back_colors": 0
      },
      "components": [
        {"code": "material-piquet", "selected": true, "quantity": "1,2"},
        {"code": "process-silk-screen", "selected": true, "quantity": "0,08"}
      ]
    }
  ]
}
```

`size_grid` determina a quantidade da linha pela soma das grades; nos demais casos `quantity` é obrigatória. `components[].quantity` informa consumo **por unidade produzida** na unidade cadastrada para cada componente (por exemplo, rolo, folha, m² ou hora). O sistema multiplica esse valor pela quantidade da linha e guarda consumo unitário e total; não presume conversões nem rendimento. Campo condicional invisível não persiste no snapshot.

As regras críticas relacionam fachada, mídia frontlight, adesivos, camisa polo, camiseta básica, cartão e pasta aos componentes correspondentes às respostas do wizard. A camiseta básica vincula tecido e técnica (silk screen, DTF, DTG, sublimação ou vinil têxtil) aos respectivos insumos. Para DTF/DTG, o wizard calcula por peça a área A4/A3/A2 selecionada ou a área personalizada em mm, multiplica pelo número de estampas e pela grade e grava a origem `wizard`; arredonda a área para cima aos milésimos de m² para não subestimar impressão/transfer. O consumo de tinta DTG permanece manual porque varia por perfil de impressão. Adesivo exige o vinil escolhido, impressão e aplicação; a laminação exige o filme correspondente (brilho, fosco ou antirrisco) e o processo de laminação, e o recorte em plotter é incluído quando marcado. Acabamentos marcados, corte especial e taxas de terceiros selecionadas não podem ser omitidos da ficha de custo; a orelha da pasta exige que a bolsa também esteja selecionada. O nesting integrado por bobina também cobre adesivo e precifica a área real consumida. Para os demais presets, o consumo segue informado pelo operador até que a gráfica configure rendimentos/fichas técnicas reais; dimensões informativas não são convertidas automaticamente em material ou custo.

Flyers e folders promocionais também aceitam nesting por folha: o servidor confere as dimensões abertas da peça e vincula o papel escolhido à variante de estoque correspondente. A contagem de folhas usa apenas as medidas e folgas informadas e o perfil de folha cadastrado pela gráfica; não presume sangria, pinça ou imposição de gráfica.

As escolhas de papel em flyer, folder, envelope e papel timbrado são seleções limitadas aos materiais cadastrados e obrigam o componente compatível. Folders exigem o processo de dobra; envelopes exigem corte e vinco. Talões escolhem estoque de 2 ou 3 vias e só incluem numeração sequencial quando marcada. Agenda/caderno e cardápio vinculam a encadernação escolhida (espiral, Wire-O ou capa dura quando disponível) ao acabamento correspondente; laminação de cardápio e bastões/cordinha de banner também são exigidos quando selecionados. Caneca obriga a base de cerâmica ou polímero e uma única técnica de personalização (sublimação ou outro processo genérico); rótulo em bobina ou cartela exige o substrato e os processos de impressão/corte compatíveis. Materiais e processos mutuamente exclusivos não podem ser cobrados juntos. O catálogo não atribui preços, consumo, rendimento ou margem: a organização informa esses valores antes de aprovar um orçamento.

Uniforme profissional, moletom, avental, boné e camiseta básica vinculam a base têxtil e a personalização escolhida aos componentes compatíveis. Serigrafia registra e valida as cores na frente e no verso e exige seus insumos; DTF, bordado e vinil exigem processo e material correspondentes. Para bordado, tamanho da logo, estimativa de pontos e a resposta sobre a matriz são obrigatórios quando aplicáveis; a matriz só entra no custo quando o operador informa que ela é necessária. O sistema calcula uma tela e um fotolito por cor/face uma vez por linha de produto, mão de obra e tinta por aplicação de cor em cada peça e bordado pela quantidade de mil pontos. A matriz de bordado é cobrada como um serviço uma vez por linha/arte; se a mesma matriz já estiver pronta para outra linha, o operador pode indicar que não é necessária e evitar duplicar o setup. O administrador informa o custo unitário real de cada componente; nenhum preço é presumido. Campos condicionais seguem o indicador `required` do schema, então respostas opcionais não bloqueiam o formulário. Os demais consumos têxteis continuam manuais quando não existe fórmula dimensional verificável.

O cálculo de área por formato A4/A3/A2 ou medidas personalizadas também cobre polo, moletom, avental e a aplicação avulsa DTF/DTG. O wizard pergunta o número de estampas por peça nos produtos por grade; as dimensões customizadas só aparecem e são exigidas quando a opção “Área personalizada” está ativa. Cada posição é arredondada para cima aos milésimos de m² e multiplicada pela quantidade produzida. Para DTF, impressão e filme transfer acompanham a área calculada; para DTG, somente o processo é automático e a tinta continua com consumo manual por variar conforme o perfil de impressão.

Rótulos agora podem usar a estimativa integrada de bobina ou cartela: o formato selecionado determina o material esperado, e largura/altura da etiqueta são reconciliadas no servidor com a grade. A bobina calcula automaticamente a orientação que consome menos comprimento entre as duas grades retangulares testadas; essa estimativa pressupõe que a arte possa girar, então o sentido de saída deve ser confirmado com a produção. Folhas cobram o número inteiro de chapas necessárias segundo as dimensões reais cadastradas pela gráfica.

## Wizard e nesting

Os schemas são declarativos (`version`, `fields`, `type`, `options`, `visible_when`) e validados novamente no servidor contra o preset armazenado. Tipos suportados: `boolean`, `decimal`, `integer`, `text`, `select`, `multiselect` e `size_grid`.

O estimador aceita dimensões inteiras em milímetros e testa duas grades retangulares, com e sem rotação. Em chapa, informa folhas necessárias, aproveitamento e área descartada. Em bobina, estima comprimento a consumir pela largura e orientação escolhida. Não faz nesting irregular/ótimo, não calcula sangria fora do campo de espaçamento e não inventa dimensão de mídia; o retorno deve ser tratado como estimativa.

No formulário do orçamento, o nesting integrado é persistido em `quote_items.nesting` e copiado para o snapshot da versão e da O.S. O componente calculado usa `quantity_source=nesting`; os demais mantêm `quantity_source=manual`. Material, unidade, dimensões e resposta do wizard são validados no servidor. Para produtos vendidos por m², a quantidade comercial deve fechar exatamente em área por um número inteiro de cópias, mesmo quando o consumo do componente é informado manualmente.

As medidas de chapa, folha ou bobina pertencem à configuração da organização em `organization_quote_presets.material_width_mm` e `material_length_mm`. O nesting integrado só pode ser usado quando as medidas nominais estiverem cadastradas no catálogo da empresa e não aceita sobrescrita no pedido. Para material comprado em m², a quantidade usa a área total de estoque consumida (largura da bobina × comprimento usado), com arredondamento para cima aos milésimos de m²; assim, sobra de borda e folga não somem do custo. O consumo automático substitui a quantidade manual daquele material e não é multiplicado novamente pelo número de peças.

Exemplo do objeto integrado enviado em cada item da criação de orçamento:

```json
{
  "nesting": {
    "material_code": "material-frontlight-440g",
    "material_type": "roll",
    "quantity": 4,
    "piece_width_mm": 500,
    "piece_length_mm": 250,
    "material_width_mm": 1000,
    "gap_mm": 5
  }
}
```

O API devolve os dados estimados persistidos, inclusive `consumed_quantity_milli`, `consumed_unit`, aproveitamento e `quantity_source`. O preview standalone continua sem persistir nem precificar.

No nesting de folders, as opções padrão A3 (297 × 420 mm) e SRA3 (320 × 450 mm) precisam corresponder às dimensões do papel cadastradas pela gráfica, aceitando orientação girada. Use “Outro formato” para qualquer medida personalizada; assim o rótulo escolhido não diverge da folha usada no cálculo. A3 segue o formato de folha aparada da ISO 216; SRA3 é o formato comercial de 320 × 450 mm usado para impressão com sangria ([ISO 216](https://www.iso.org/standard/36631.html), [Rayfilm — SRA3 320 × 450 mm](https://www.rayfilm.cz/cs/aktuality/formaty-sra3-320-x-450-mm-v-nabidce-rayfilm-55/)).

Na ficha de acrílico e plásticos, tipo e espessura determinam a chapa obrigatória; corte laser/router sempre precisa estar incluído, e a dobra térmica entra quando marcada. A regra vale também para consumo manual, então não depende de ativar nesting para conferir os componentes.

Para PS, PVC expandido e policarbonato, o wizard vincula o tipo à referência de chapa configurada pela empresa e não pergunta espessura: o catálogo inicial não oferece variantes de custo por espessura para esses três plásticos. O operador deve manter o custo configurado correspondente à referência de estoque usada; a seleção de espessura e o vínculo automático por faixa são oferecidos para acrílico cast.

No corte de acrílico/plásticos, laser e router CNC são processos com custos horários independentes por gráfica. O wizard seleciona somente o processo escolhido; configure os dois custos separadamente no catálogo. A opção legada agregada permanece no catálogo para compatibilidade, mas não é sugerida na ficha nova.

Roll-up usa nesting de bobina para calcular o material total consumido, incluindo a sobra de borda. A impressão é calculada separadamente pelas dimensões acabadas da arte e pela quantidade, sem cobrar a sobra como área impressa; a estrutura só entra quando o assistente informa que ela está inclusa, com uma estrutura por unidade. Copos long drink, squeezes, tirantes e brindes ecológicos têm base e personalização calculadas por peça, usando custos unitários configurados pela gráfica. Nos presets genéricos desses quatro brindes, material e técnica são respostas descritivas para a produção e não alteram automaticamente o custo; o formulário explica isso ao operador e a informação também acompanha o schema entregue pela API. Cadastre custo compatível com a combinação comercializada ou crie um preset específico antes de aprovar.

## Executar e validar

```powershell
php artisan migrate
php artisan db:seed --class="Database\Seeders\QuotePresetSeeder"
php artisan test
```

Não use `migrate:fresh` no banco local. Testes usam SQLite em memória.
