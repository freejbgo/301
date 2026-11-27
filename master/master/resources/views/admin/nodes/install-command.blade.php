<div style="padding: 4px 8px 8px; text-align: left;">
    <div style="font-weight: 600; margin-bottom: 6px;">{{ __('redi-admin-node.install.title') }}</div>
    <div style="color: #5c6b73; margin-bottom: 8px;">{{ __('redi-admin-node.install.help') }}</div>
    <textarea id="redi-node-install-cmd" readonly rows="4" onclick="this.select()" style="width: 100%; font-family: monospace;">{{ $command }}</textarea>
    <div style="margin-top: 8px;">
        <button type="button" class="btn btn-primary btn-sm" id="redi-node-install-copy">{{ __('redi-admin-node.install.copy') }}</button>
    </div>
    @if (! $hasKey)
        <div style="margin-top: 10px; color: #8a6d3b;">{{ __('redi-admin-node.install.key_missing') }}</div>
        <textarea readonly rows="2" onclick="this.select()" style="width: 100%; font-family: monospace; margin-top: 6px;">{{ $publish }}</textarea>
    @endif
</div>
