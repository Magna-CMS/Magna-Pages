<x-filament-panels::page>
    {{-- Which menu, and the two menu-level actions. --}}
    <div class="flex flex-wrap items-end gap-3">
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Menu</span>
            <x-filament::input.wrapper class="w-64">
                <x-filament::input.select wire:change="selectMenu($event.target.value)">
                    @if($selectedMenuId === null)
                        <option value="" selected>— no menus yet —</option>
                    @endif
                    @foreach($this->menuOptions() as $id => $name)
                        <option value="{{ $id }}" @selected($id === $selectedMenuId)>{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">New menu</span>
            <x-filament::input.wrapper class="w-56">
                <x-filament::input type="text" wire:model="newMenuName" wire:keydown.enter="createMenu"
                                   placeholder="Primary navigation" />
            </x-filament::input.wrapper>
        </label>

        <x-filament::button wire:click="createMenu" color="gray" outlined>Create</x-filament::button>
    </div>

    @if($selectedMenuId !== null)
        <div class="mt-6 grid items-start gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">

            {{-- ── Left: what can be added ───────────────────────────── --}}
            <div class="space-y-3">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Add menu items</h2>

                @foreach(['pages' => 'Pages', 'plugin' => 'Plugin pages', 'custom' => 'Custom link'] as $panel => $panelLabel)
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
                        <button type="button"
                                wire:click="togglePanel('{{ $panel }}')"
                                aria-expanded="{{ $openPanel === $panel ? 'true' : 'false' }}"
                                class="flex w-full items-center justify-between px-4 py-2.5 text-sm font-medium text-gray-900 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5">
                            {{ $panelLabel }}
                            <x-filament::icon
                                :icon="$openPanel === $panel ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'"
                                class="h-4 w-4 text-gray-400"
                            />
                        </button>

                        @if($openPanel === $panel)
                            <div class="space-y-3 border-t border-gray-200 p-4 dark:border-white/10">
                                @if($panel === 'custom')
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">URL</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" wire:model="customLinkUrl" />
                                        </x-filament::input.wrapper>
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Link text</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" wire:model="customLinkLabel"
                                                               wire:keydown.enter="addCustomLink" />
                                        </x-filament::input.wrapper>
                                    </label>
                                    <x-filament::button wire:click="addCustomLink" size="sm" color="gray" outlined>
                                        Add to menu
                                    </x-filament::button>
                                @else
                                    @php
                                        $sourceRows = $panel === 'pages' ? $this->pageRows() : $this->pluginPageRows();
                                        $model = $panel === 'pages' ? 'checkedPages' : 'checkedPluginPages';
                                        $action = $panel === 'pages' ? 'addCheckedPages' : 'addCheckedPluginPages';
                                    @endphp

                                    <x-filament::input.wrapper>
                                        <x-filament::input type="search" wire:model.live.debounce.300ms="sourceSearch"
                                                           placeholder="Search" />
                                    </x-filament::input.wrapper>

                                    <div class="max-h-64 space-y-1 overflow-y-auto pr-1">
                                        @forelse($sourceRows as $sourceRow)
                                            <label class="flex items-start gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                                <x-filament::input.checkbox
                                                    class="mt-0.5"
                                                    value="{{ $sourceRow['id'] }}"
                                                    wire:model="{{ $model }}"
                                                />
                                                <span class="min-w-0">
                                                    <span class="block truncate text-gray-900 dark:text-white">{{ $sourceRow['label'] }}</span>
                                                    @if($sourceRow['meta'] !== '')
                                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $sourceRow['meta'] }}</span>
                                                    @endif
                                                </span>
                                            </label>
                                        @empty
                                            <p class="px-2 py-3 text-sm text-gray-500 dark:text-gray-400">Nothing to add here yet.</p>
                                        @endforelse
                                    </div>

                                    <x-filament::button wire:click="{{ $action }}" size="sm" color="gray" outlined>
                                        Add to menu
                                    </x-filament::button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach

                {{-- Which optional fields the item panels show. --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Show fields</h3>
                    <div class="space-y-1.5">
                        @foreach(['title_attr' => 'Title attribute', 'css_class' => 'CSS classes', 'rel' => 'Link relationship', 'description' => 'Description'] as $field => $fieldLabel)
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <x-filament::input.checkbox wire:model.live="screen.{{ $field }}" />
                                {{ $fieldLabel }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ── Right: the menu itself ────────────────────────────── --}}
            <div class="space-y-4">
                <div class="flex flex-wrap items-end gap-3">
                    <label class="block grow">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Menu name</span>
                        <x-filament::input.wrapper class="max-w-sm">
                            <x-filament::input type="text" wire:model="menuName" wire:keydown.enter="renameMenu" />
                        </x-filament::input.wrapper>
                    </label>
                    <x-filament::button wire:click="renameMenu" size="sm" color="gray" outlined>Rename</x-filament::button>
                    <x-filament::button wire:click="deleteMenu" size="sm" color="danger" outlined
                                        wire:confirm="Delete this menu? Anything rendering it will show nothing.">
                        Delete menu
                    </x-filament::button>
                </div>

                {{-- Where this menu appears. A theme layout and a nav block
                     both point at the HANDLE, so it is the thing worth
                     showing — and the reason renaming leaves it alone. --}}
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Shown wherever a nav block or theme layout asks for
                    <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ $this->selectedHandle() }}</code>.
                    Renaming the menu keeps this the same.
                </p>

                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Menu structure</h2>
                    <x-filament::button wire:click="save" size="sm">Save menu</x-filament::button>
                </div>

                @if($rows === [])
                    <p class="rounded-xl border-2 border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                        Nothing in this menu yet. Add pages or a custom link from the left.
                    </p>
                @else
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Drag a row to reorder it, or drag it right to nest it under the row above.
                        Every move is also on the row's own buttons.
                    </p>

                    <ol class="max-w-3xl space-y-2"
                        x-data="magnaMenuDrag()"
                        @dragover.prevent="over($event)"
                        @drop.prevent="drop()">
                        @foreach($rows as $index => $row)
                            @include('magna-pages::filament.partials.menu-row', [
                                'row' => $row,
                                'index' => $index,
                                'isOpen' => in_array($row['key'], $openRows, true),
                            ])
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>
    @endif

    @script
    <script>
        /*
         * Dragging a row, and dragging it sideways to nest.
         *
         * The drop is sent as (from, to, depth) and MenuTree decides what
         * it means — the same call the arrow buttons make. Nothing here
         * edits the list: a drag layer that rearranged its own copy would
         * be a second implementation of the tree rules, and the two would
         * disagree the first time a subtree was involved.
         */
        Alpine.data('magnaMenuDrag', () => ({
            from: null,
            to: null,
            depth: 0,

            start(index, depth) {
                this.from = index
                this.to = index
                this.depth = depth
            },

            over(event) {
                if (this.from === null) {
                    return
                }

                const row = event.target.closest('[data-menu-row]')
                if (row) {
                    this.to = Number(row.dataset.menuRow)
                }

                // Horizontal travel is the nesting gesture, one level per
                // indent step, which is how the row already reads.
                const list = event.currentTarget.getBoundingClientRect()
                const indent = Math.round((event.clientX - list.left) / 32)
                this.depth = Math.max(0, Math.min(indent, {{ \Magna\Pages\Menus\MenuTree::MAX_DEPTH }}))
            },

            drop() {
                if (this.from === null) {
                    return
                }

                $wire.dropRow(this.from, this.to, this.depth)
                this.from = null
            },
        }))
    </script>
    @endscript
</x-filament-panels::page>
