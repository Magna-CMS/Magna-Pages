<x-filament-panels::page>
    @if($themeName === null)
        <div class="rounded-xl border border-dashed border-gray-300 py-16 text-center dark:border-white/10">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">No active theme</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Activate a theme before scheduling a design change.</p>
        </div>
    @else
        <div class="space-y-6">
            @if($active !== null)
                <div class="rounded-xl border border-primary-300 bg-primary-50/60 p-4 text-sm dark:border-primary-500/40 dark:bg-primary-500/10">
                    <strong class="text-gray-900 dark:text-white">{{ $active->label }}</strong>
                    is live now{{ $active->ends_at !== null ? ' until '.$active->ends_at->format('D j M, H:i') : '' }}.
                </div>
            @endif

            <div class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Schedule a change</h2>

                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Label</span>
                        <input type="text" wire:model="label" placeholder="Summer sale"
                               class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Starts</span>
                        <input type="datetime-local" wire:model="startsAt"
                               class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Ends (optional)</span>
                        <input type="datetime-local" wire:model="endsAt"
                               class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    </label>
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach($declared as $name => $current)
                        <label class="flex items-center gap-2">
                            <span class="w-40 shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">{{ str_replace('--', '', $name) }}</span>
                            <input type="text" wire:model="tokens.{{ $name }}" placeholder="{{ $current }}"
                                   class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        </label>
                    @endforeach
                </div>

                <x-filament::button wire:click="schedule">Schedule</x-filament::button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <tbody>
                        @forelse($schedules as $schedule)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $schedule->label }}</td>
                                <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">
                                    {{ $schedule->starts_at->format('D j M Y, H:i') }}
                                    @if($schedule->ends_at !== null) — {{ $schedule->ends_at->format('D j M Y, H:i') }} @endif
                                </td>
                                <td class="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400">{{ count($schedule->tokens) }} tokens</td>
                                <td class="px-4 py-2.5 text-right">
                                    <button wire:click="cancelSchedule('{{ $schedule->id }}')"
                                            wire:confirm="Cancel this scheduled design change?"
                                            class="text-xs font-semibold text-gray-500 hover:text-danger-600">Cancel</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">Nothing scheduled.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
