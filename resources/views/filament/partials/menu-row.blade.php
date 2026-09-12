{{--
    One row of the menu structure.

    Collapsed it says what it is and where it points; opened it edits.
    That is the shape WordPress settled on and the reason is the screen
    itself: a menu of twenty items with every field open is unreadable,
    and the thing an editor is usually doing is ordering, not editing.

    Every drag has a button beside it. A row that can only be arranged by
    dragging is a row some people cannot arrange at all.

    Fields use the panel's own input components rather than hand-rolled
    classes: a bare input on this surface renders the same colour as the
    row behind it, which reads as static text until you click it.

    Receives: $row, $index, $isOpen.
--}}
@php
    $depth = (int) ($row['depth'] ?? 0);
    $label = trim((string) ($row['label'] ?? '')) !== '' ? $row['label'] : '(no label)';
@endphp

<li data-menu-row="{{ $index }}"
    style="margin-inline-start: {{ $depth * 2 }}rem"
    class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">

    <div class="flex items-center gap-2 px-3 py-2">
        <span draggable="true"
              @dragstart="start({{ $index }}, {{ $depth }})"
              class="cursor-grab select-none px-1 text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
              aria-hidden="true"
              title="Drag to reorder">⠿</span>

        <button type="button"
                wire:click="toggleRow('{{ $row['key'] }}')"
                aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                class="flex min-w-0 grow items-center gap-2 py-1 text-left">
            <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $label }}</span>
            <x-filament::badge size="xs" color="gray">{{ $this->describeRow($row) }}</x-filament::badge>
            @if($depth > 0)
                <span class="shrink-0 text-[11px] text-gray-500 dark:text-gray-400">sub item</span>
            @endif
            <x-filament::icon
                :icon="$isOpen ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'"
                class="ml-auto h-4 w-4 shrink-0 text-gray-400"
            />
        </button>
    </div>

    @if($isOpen)
        <div class="space-y-3 border-t border-gray-200 px-3 py-3 dark:border-white/10">
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Navigation label</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="text" wire:model="rows.{{ $index }}.label" />
                    </x-filament::input.wrapper>
                </label>

                @if(($row['type'] ?? 'url') === 'url')
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">URL</span>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="rows.{{ $index }}.url" />
                        </x-filament::input.wrapper>
                    </label>
                @endif

                @if($screen['title_attr'])
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Title attribute</span>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="rows.{{ $index }}.settings.title_attr" />
                        </x-filament::input.wrapper>
                    </label>
                @endif

                @if($screen['css_class'])
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">CSS classes</span>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="rows.{{ $index }}.settings.css_class" />
                        </x-filament::input.wrapper>
                    </label>
                @endif

                @if($screen['rel'])
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Link relationship (rel)</span>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="rows.{{ $index }}.settings.rel" />
                        </x-filament::input.wrapper>
                    </label>
                @endif
            </div>

            @if($screen['description'])
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Description</span>
                    <x-filament::input.wrapper>
                        <textarea rows="2" wire:model="rows.{{ $index }}.settings.description"
                                  class="block w-full border-none bg-transparent px-3 py-1.5 text-base text-gray-950 outline-none placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 sm:text-sm dark:text-white"></textarea>
                    </x-filament::input.wrapper>
                </label>
            @endif

            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <x-filament::input.checkbox wire:model="rows.{{ $index }}.new_tab" />
                Open in a new tab
            </label>

            {{-- The keyboard equivalent of every drag this row supports. --}}
            <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 pt-3 dark:border-white/10">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Move</span>

                <x-filament::button size="xs" color="gray" outlined
                                    wire:click="moveUp({{ $index }})" :disabled="$index === 0">Up</x-filament::button>
                <x-filament::button size="xs" color="gray" outlined
                                    wire:click="moveDown({{ $index }})" :disabled="$index === count($rows) - 1">Down</x-filament::button>
                <x-filament::button size="xs" color="gray" outlined
                                    wire:click="indent({{ $index }})" :disabled="! $this->canIndent($index)">Nest under above</x-filament::button>
                <x-filament::button size="xs" color="gray" outlined
                                    wire:click="outdent({{ $index }})" :disabled="$depth === 0">Out one level</x-filament::button>
                <x-filament::button size="xs" color="gray" outlined
                                    wire:click="moveToTop({{ $index }})" :disabled="$index === 0">To top</x-filament::button>

                <x-filament::button size="xs" color="danger" outlined class="ml-auto"
                                    wire:click="removeRow({{ $index }})">Remove</x-filament::button>
            </div>
        </div>
    @endif
</li>
