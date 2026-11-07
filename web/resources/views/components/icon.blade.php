@props(['name' => 'grid'])
<svg {{ $attributes->merge(['class' => 'icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('grid') <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/> @break
@case('chart') <path d="M4 4v16h17M8 15l4-5 4 3 5-8"/> @break
@case('clock') <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/> @break
@case('settings') <path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3" fill="var(--surface,white)"/><circle cx="16" cy="17" r="3" fill="var(--surface,white)"/> @break
@case('food') <path d="M3 12h18l-2 7H5l-2-7ZM7 8V5m5 3V3m5 5V5"/> @break
@case('water') <path d="M12 3S5 10 5 15a7 7 0 0 0 14 0c0-5-7-12-7-12Z"/><path d="M9 16a3 3 0 0 0 3 3"/> @break
@case('motion') <path d="M5 8a10 10 0 0 1 14 0M8 11a6 6 0 0 1 8 0M3 5a14 14 0 0 1 18 0"/><circle cx="12" cy="16" r="2"/> @break
@case('arrow') <path d="M5 12h14m-5-5 5 5-5 5"/> @break
@case('chevron') <path d="m8 5 7 7-7 7"/> @break
@case('logout') <path d="M9 4H4v16h5m5-13 5 5-5 5M9 12h11"/> @break
@case('station') <rect x="4" y="7" width="16" height="14" rx="3"/><path d="M8 7V3h8v4M8 12h8M8 16h3"/> @break
@case('check') <path d="m5 12 4 4L19 6"/> @break
@case('close') <path d="m6 6 12 12M6 18 18 6"/> @break
@case('menu') <path d="M4 6h16M4 12h16M4 18h16"/> @break
@case('shield') <path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/> @break
@endswitch
</svg>
