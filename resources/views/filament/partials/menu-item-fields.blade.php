{{-- Shared field row for a menu item; $prefix is the Livewire state path. --}}
<div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
    <div>
        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Label</label>
        <input type="text"
               wire:model.blur="{{ $prefix }}.label"
               class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
    </div>

    <div>
        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Type</label>
        <select wire:model.live="{{ $prefix }}.type"
                class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
            <option value="url">Custom URL</option>
            <option value="page">Page</option>
        </select>
    </div>

    @if(($item['type'] ?? 'url') === 'page')
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Page</label>
            <select wire:model.blur="{{ $prefix }}.page_id"
                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                <option value="">— choose —</option>
                @foreach($this->pageOptions() as $pageId => $pageTitle)
                    <option value="{{ $pageId }}">{{ $pageTitle }}</option>
                @endforeach
            </select>
        </div>
    @else
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">URL</label>
            <input type="text"
                   wire:model.blur="{{ $prefix }}.url"
                   placeholder="/path or https://…"
                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
        </div>
    @endif

    <div>
        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Open in</label>
        <select wire:model.blur="{{ $prefix }}.target"
                class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
            <option value="">Same tab</option>
            <option value="_blank">New tab</option>
        </select>
    </div>
</div>
