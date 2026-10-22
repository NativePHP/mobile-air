<native:column>
    <native:pressable ref="open-listing" @navigate="route('listing.show', ['id' => 5])">
        <native:text>Open listing</native:text>
    </native:pressable>
    <native:pressable ref="replace-listing" @navigate.replace="route('listing.show', ['id' => 6, 'tab' => 'reviews'])">
        <native:text>Replace listing</native:text>
    </native:pressable>
    <native:pressable ref="open-settings" @navigate.exitToWeb="url('/settings')">
        <native:text>Open settings</native:text>
    </native:pressable>
    <native:pressable ref="open-terms" @navigate.exitToWeb="'https://example.com/terms'">
        <native:text>Open terms</native:text>
    </native:pressable>
    <native:pressable ref="open-external" @navigate="'https://nativephp.com'">
        <native:text>Open external</native:text>
    </native:pressable>
    <native:pressable ref="send-mail" @navigate="'mailto:hello@example.com'">
        <native:text>Send mail</native:text>
    </native:pressable>
</native:column>
