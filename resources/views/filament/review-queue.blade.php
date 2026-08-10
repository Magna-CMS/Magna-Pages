<x-filament-panels::page>
    @forelse($requests as $request)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-medium text-gray-900 dark:text-white">{{ $request['title'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $request['requester'] }} · {{ $request['age'] }}
                    </p>
                    @if($request['note'] !== null)
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-300">
                            “{{ $request['note'] }}”
                        </p>
                    @endif
                </div>

                <div class="flex flex-col items-end gap-2">
                    <div class="flex gap-2">
                        <x-filament::button tag="a" size="sm" color="gray" href="{{ $request['builderUrl'] }}">
                            Open in builder
                        </x-filament::button>
                        <x-filament::button size="sm" wire:click="approve('{{ $request['id'] }}')">
                            Approve &amp; publish
                        </x-filament::button>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="text"
                               wire:model="returnNotes.{{ $request['id'] }}"
                               placeholder="What should change?"
                               class="w-64 rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <x-filament::button size="sm" color="warning" wire:click="returnRequest('{{ $request['id'] }}')">
                            Return
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-gray-200 px-4 py-10 text-center text-gray-500 dark:border-white/10 dark:text-gray-400">
            Nothing waiting for review.
        </div>
    @endforelse
</x-filament-panels::page>
