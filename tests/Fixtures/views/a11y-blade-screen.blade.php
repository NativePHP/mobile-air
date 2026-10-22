<native:column>
    <native:pressable a11y-label="Open settings" a11y-hint="Opens the settings screen" @tap="noop">
        <native:icon name="gear" />
    </native:pressable>
    <native:image src="https://example.com/logo.png" alt="Company logo" />
    <native:row a11y-label="Events: 5, last 10h ago">
        <native:text>Events</native:text>
        <native:text>5</native:text>
    </native:row>
</native:column>
