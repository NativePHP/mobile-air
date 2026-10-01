<x-layout title="Chat">
    <x-slot:actions>
        <native:top-bar-action id="info" icon="info" label="Info" @tap="showInfo" />
    </x-slot:actions>

    <native:column class="flex-1">
        <native:text>Chat detail</native:text>
    </native:column>
</x-layout>
