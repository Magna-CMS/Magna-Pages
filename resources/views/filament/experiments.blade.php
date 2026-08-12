<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Sections that share an experiment name are variants of it. Every visitor is
            assigned one variant and keeps it; a click on any element marked
            <code class="font-mono text-xs">data-magna-goal</code> inside the shown variant counts as a conversion.
        </p>

        @forelse($experiments as $name => $variants)
            <div class="rounded-xl border border-gray-200 dark:border-white/10">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $name }}</span>
                    <button wire:click="resetExperiment('{{ $name }}')"
                            wire:confirm="Reset this experiment's counters to zero?"
                            class="text-xs font-semibold text-gray-500 hover:text-danger-600">Reset</button>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2">Variant</th>
                            <th class="px-4 py-2">Exposures</th>
                            <th class="px-4 py-2">Conversions</th>
                            <th class="px-4 py-2">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($variants as $stat)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-4 py-2 font-mono text-xs text-gray-900 dark:text-white">{{ $stat->variant }}</td>
                                <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ number_format($stat->exposures) }}</td>
                                <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ number_format($stat->conversions) }}</td>
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $stat->rate() }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 py-16 text-center dark:border-white/10">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">No experiments yet</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Give two sections the same experiment name and different variant names, then publish.
                </p>
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
