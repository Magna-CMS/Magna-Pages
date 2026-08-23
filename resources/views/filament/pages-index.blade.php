<x-filament-panels::page>
    {{-- New page: title in, builder open. --}}
    <div class="flex flex-wrap items-end gap-2">
        <div>
            <label for="new-page-title" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">New page</label>
            <input type="text"
                   id="new-page-title"
                   wire:model="newPageTitle"
                   wire:keydown.enter="createPage"
                   placeholder="About us"
                   class="w-64 rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
        </div>
        <x-filament::button wire:click="createPage">Create &amp; open builder</x-filament::button>
        <x-filament::button tag="a" color="gray" href="{{ $createFieldsUrl }}">Create with fields</x-filament::button>
    </div>

    {{-- Recent work, newest change first — a shortcut, not the index.
         Bounded on purpose: this table used to render every page on the
         site, which pushed the templates section below it out of reach. --}}
    <div class="flex flex-wrap items-center justify-between gap-2">
        <input type="search"
               wire:model.live.debounce.300ms="pageSearch"
               placeholder="Search pages…"
               class="w-64 rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
        <p class="text-xs text-gray-500 dark:text-gray-400">
            @if($pageTotal > $pageLimit)
                Showing the {{ $pageLimit }} most recently changed of {{ $pageTotal }}.
                <a href="{{ $allPagesUrl }}" class="underline">See all pages</a>.
            @else
                {{ $pageTotal }} {{ Str::plural('page', $pageTotal) }}.
            @endif
        </p>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left dark:border-white/10">
                    <th class="px-4 py-2.5 font-medium text-gray-700 dark:text-gray-300">Title</th>
                    <th class="px-4 py-2.5 font-medium text-gray-700 dark:text-gray-300">Path</th>
                    <th class="px-4 py-2.5 font-medium text-gray-700 dark:text-gray-300">Status</th>
                    <th class="px-4 py-2.5 font-medium text-gray-700 dark:text-gray-300">Updated</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($pages as $page)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                        <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $page['title'] }}</td>
                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">/{{ $page['path'] }}</td>
                        <td class="px-4 py-2.5">
                            <x-filament::badge :color="$page['status'] === 'published' ? 'success' : 'gray'">
                                {{ $page['status'] }}
                            </x-filament::badge>
                            @if($page['pendingApproval'])
                                <x-filament::badge color="warning">review</x-filament::badge>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $page['updated'] }}</td>
                        <td class="px-4 py-2.5">
                            <div class="flex justify-end gap-2">
                                <x-filament::button tag="a" size="sm" href="{{ $page['builderUrl'] }}">
                                    Open builder
                                </x-filament::button>
                                <x-filament::button tag="a" size="sm" color="gray" href="{{ $page['editUrl'] }}">
                                    Edit fields
                                </x-filament::button>
                                @if($page['viewUrl'] !== null)
                                    <x-filament::button tag="a" size="sm" color="gray"
                                                        href="{{ $page['viewUrl'] }}" target="_blank">
                                        View
                                    </x-filament::button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                            No pages yet — give one a title above and open the builder.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Templates: the site's header, footer, and reusable parts.

         Its own heading because it was previously an unlabelled block
         below the page table, and on a site with more than a screenful of
         pages that made "design the header" something you had to already
         know was down here. --}}
    <h2 class="mt-6 text-base font-semibold text-gray-900 dark:text-white">Headers, footers &amp; parts</h2>
    <p class="-mt-4 text-sm text-gray-500 dark:text-gray-400">
        Design a header once and use it on every page, or give one page its own.
    </p>
    <div class="flex flex-wrap items-end gap-2">
        <div>
            <label for="new-part-title" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">New template part</label>
            <input type="text"
                   id="new-part-title"
                   wire:model="newPartTitle"
                   wire:keydown.enter="createPart"
                   placeholder="Header"
                   class="w-64 rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
        </div>
        <div>
            {{-- Asked at creation, not left for later: a part with no role
                 is invisible to the header and footer pickers, so a header
                 nobody can choose is a header nobody made. --}}
            <label for="new-part-role" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Used as</label>
            <select id="new-part-role"
                    wire:model="newPartRole"
                    class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                <option value="generic">A part referenced by a page</option>
                <option value="header">The site header</option>
                <option value="footer">The site footer</option>
            </select>
        </div>
        <x-filament::button color="gray" wire:click="createPart">Create part &amp; open builder</x-filament::button>
        <div>
            <label for="new-popup-title" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">New popup</label>
            <input type="text"
                   id="new-popup-title"
                   wire:model="newPopupTitle"
                   wire:keydown.enter="createPopup"
                   placeholder="Summer sale"
                   class="w-64 rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
        </div>
        <x-filament::button color="gray" wire:click="createPopup">Create popup &amp; open builder</x-filament::button>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm">
            <tbody>
                @forelse($templates as $template)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                        <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $template['title'] }}</td>
                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $template['slug'] }}</td>
                        <td class="px-4 py-2.5">
                            <x-filament::badge color="gray">{{ $template['kind'] }}</x-filament::badge>
                            @if($template['role'] !== 'generic')
                                <x-filament::badge color="info">{{ $template['role'] }}</x-filament::badge>
                                {{-- Sticky is chrome behaviour, so it is
                                     offered only on chrome. --}}
                                <button type="button"
                                        wire:click="toggleSticky('{{ $template['id'] }}')"
                                        class="text-xs underline decoration-dotted">
                                    {{ $template['sticky'] ? 'Sticky' : 'Not sticky' }}
                                </button>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <x-filament::badge :color="$template['status'] === 'published' ? 'success' : 'gray'">
                                {{ $template['status'] }}
                            </x-filament::badge>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex justify-end">
                                <x-filament::button tag="a" size="sm" href="{{ $template['builderUrl'] }}">
                                    Open builder
                                </x-filament::button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            No templates yet — create a "Header" part to design your site's header once, everywhere.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
