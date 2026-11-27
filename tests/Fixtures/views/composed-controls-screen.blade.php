@php use Native\Mobile\Edge\Layouts\Builders\NavAction; @endphp
<native:column>
    <native:pressable ref="terms" @press="toggleTerms" a11y-role="checkbox" :a11y-checked="$accepted" a11y-label="Accept the terms">
        <native:text>Accept the terms</native:text>
    </native:pressable>

    <native:gesture-area ref="volume" a11y-role="adjustable" a11y-label="Volume" a11y-value="{{ $volume }}%"
        :a11y-value-now="$volume" a11y-value-min="0" a11y-value-max="100"
        @a11yIncrement="louder" @a11yDecrement="quieter">
        <native:text>Volume</native:text>
    </native:gesture-area>

    <native:column ref="priority" a11y-role="radiogroup" a11y-label="Priority" :a11y-error="$error">
        <native:pressable ref="routine" @press="choose('routine')" a11y-role="radio" :a11y-selected="$priority === 'routine'">
            <native:text>Routine</native:text>
        </native:pressable>
        <native:pressable ref="urgent" @press="choose('urgent')" a11y-role="radio" a11y-selected="false" disabled>
            <native:text>Urgent</native:text>
        </native:pressable>
    </native:column>

    <native:pressable ref="row" @press="close" a11y-state="3 unread" a11y-expanded="true"
        :a11y-actions="[NavAction::make('delete')->label('Delete')->press('remove(42)'), NavAction::divider()]">
        <native:text>Inbox</native:text>
    </native:pressable>

    <native:pressable ref="draft" @press="close" disabled="disabled"><native:text>Save draft</native:text></native:pressable>
    <native:pressable ref="copy" @press="close"><native:text>Save copy</native:text></native:pressable>

    <native:text ref="sync" a11y-live="polite">Synced</native:text>
    <native:text ref="status" a11y-live :a11y-state="null">Idle</native:text>
    <native:row ref="toolbar" :a11y-error="null" :a11y-checked="null" :a11y-actions="[]"><native:text>Toolbar</native:text></native:row>
    <native:icon ref="star" name="star" a11y-hidden />

    @if ($complete)
        <native:stack ref="dialog" a11y-modal a11y-label="Discard draft?" a11y-pane-title="Discard draft?" @a11yDismiss="close">
            <native:text>Discard draft?</native:text>
        </native:stack>
    @endif
</native:column>
