<dl class="grid gap-3 sm:grid-cols-2">
    @foreach($summary as $label => $value)
        <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="break-all">{{ $value }}</dd></div>
    @endforeach
</dl>
<details class="mt-4"><summary class="cursor-pointer text-sm">Review protection rules ({{ count($rules) }})</summary>
    <div class="mt-2 space-y-2">
        @forelse($rules as $rule)
            <div class="text-sm"><span class="break-all">{{ $rule['pattern'] }}</span> · {{ \Arcturus\ModpackManager\Services\ProtectionPolicy::ACTIONS[$rule['mode']] }}{{ ($rule['enabled'] ?? true) ? '' : ' · disabled' }}
                @if($rule['classification'] ?? null)<span class="text-gray-500"> · {{ str_replace('_', ' ', $rule['classification']) }}</span>@endif
            </div>
        @empty<p class="text-sm">Empty — normal three-way comparison.</p>@endforelse
    </div>
</details>
<p class="mt-4 text-sm">The baseline is unverified. Prepare the exact upstream release and compare it with the existing installation before approving updates. Adoption does not change files or control server power.</p>
@if($warnings)<ul class="mt-2 list-inside list-disc text-sm">@foreach($warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>@endif
