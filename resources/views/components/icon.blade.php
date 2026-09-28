@props(['name' => 'grid'])
<svg {{ $attributes->merge(['class' => 'icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('chart')<path d="M3 3v18h18M8 15l4-4 4 3 5-7"/><path d="M19 7h2v2"/>@break
@case('quote')<path d="M8 7H5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h3l-2 5M19 7h-3a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h3l-2 5"/>@break
@case('orders')<path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/>@break
@case('chat')<path d="M21 11.5a8.5 8.5 0 0 1-12.8 7.3L3 20l1.2-4.1A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 11h.01M12 11h.01M16 11h.01"/>@break
@case('mobile')<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>@break
@case('catalog')<path d="m12 3 9 5-9 5-9-5 9-5ZM3 8v9l9 5 9-5V8M12 13v9M7.5 5.5l9 5"/>@break
@case('users')<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('search')<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>@break
@case('arrow')<path d="M5 12h14m-5-5 5 5-5 5"/>@break
@case('arrow-down')<path d="M12 4v15m-6-6 6 6 6-6"/>@break
@case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
@case('exit')<path d="M9 21H4V3h5M9 12h12m-5-5 5 5-5 5"/>@break
@case('print')<path d="M6 9V3h12v6M6 17H3V9h18v8h-3M6 14h12v7H6zM17 11h1"/>@break
@default<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
@endswitch
</svg>
