<dl class="space-y-2 text-sm">
    <div>
        <dt class="font-medium">Archive</dt>
        <dd class="break-all">{{ $archive['filename'] ?? '' }}</dd>
        <dd class="break-all">{{ $archive['url'] ?? '' }}</dd>
    </div>
    <div>
        <dt class="font-medium">SHA-256</dt>
        <dd><code class="break-all">{{ $archive['sha256'] ?? '' }}</code></dd>
    </div>
    <div>
        <dt class="font-medium">Size</dt>
        <dd>{{ number_format((int) ($archive['size'] ?? 0)) }} bytes</dd>
    </div>
</dl>
