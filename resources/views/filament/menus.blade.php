<x-filament-panels::page>
    {{-- Menu picker + create --}}
    <div class="flex flex-wrap items-end gap-3">
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Menu</label>
            <select wire:change="selectMenu($event.target.value)"
                    class="w-64 rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                @if($selectedMenuId === null)
                    <option value="" selected>— no menus yet —</option>
                @endif
                @foreach($this->menuOptions() as $id => $name)
                    <option value="{{ $id }}" @selected($id === $selectedMenuId)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-end gap-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">New menu</label>
                <input type="text"
                       wire:model="newMenuName"
                       wire:keydown.enter="createMenu"
                       placeholder="Primary navigation"
                       class="w-56 rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
            </div>
            <x-filament::button wire:click="createMenu" color="gray" outlined>Create</x-filament::button>
        </div>
    </div>

    @if($selectedMenuId !== null)
        <div class="mt-6 space-y-3">
            @foreach($items as $i => $item)
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                    @include('magna-pages::filament.partials.menu-item-fields', ['prefix' => "items.{$i}", 'item' => $item])

                    <div class="mt-2 flex items-center gap-3 text-xs">
                        <button type="button" wire:click="moveItem({{ $i }}, -1)" class="text-gray-400 hover:text-indigo-600">↑ Up</button>
                        <button type="button" wire:click="moveItem({{ $i }}, 1)" class="text-gray-400 hover:text-indigo-600">↓ Down</button>
                        <button type="button" wire:click="addItem({{ $i }})" class="text-gray-400 hover:text-indigo-600">+ Sub-item</button>
                        <button type="button" wire:click="removeItem({{ $i }})" class="ml-auto text-gray-400 hover:text-red-500">Remove</button>
                    </div>

                    @if(!empty($item['children']))
                        <div class="mt-3 space-y-2 border-l-2 border-gray-100 pl-4 dark:border-white/10">
                            @foreach($item['children'] as $j => $child)
                                <div class="rounded-lg border border-gray-100 p-3 dark:border-white/10">
                                    @include('magna-pages::filament.partials.menu-item-fields', ['prefix' => "items.{$i}.children.{$j}", 'item' => $child])

                                    <div class="mt-2 flex items-center gap-3 text-xs">
                                        <button type="button" wire:click="moveItem({{ $i }}, -1, {{ $j }})" class="text-gray-400 hover:text-indigo-600">↑ Up</button>
                                        <button type="button" wire:click="moveItem({{ $i }}, 1, {{ $j }})" class="text-gray-400 hover:text-indigo-600">↓ Down</button>
                                        <button type="button" wire:click="removeItem({{ $i }}, {{ $j }})" class="ml-auto text-gray-400 hover:text-red-500">Remove</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            <button type="button"
                    wire:click="addItem"
                    class="flex w-full items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-gray-200 py-3 text-sm text-gray-400 hover:border-indigo-400 hover:text-indigo-600 dark:border-white/10 dark:hover:border-indigo-500">
                <x-heroicon-o-plus class="h-4 w-4"/>
                Add item
            </button>

            <div class="flex justify-end pt-2">
                <x-filament::button wire:click="save">Save menu</x-filament::button>
            </div>
        </div>
    @endif
</x-filament-panels::page>
