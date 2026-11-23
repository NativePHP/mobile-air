package com.nativephp.mobile.ui.nativerender

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.IconButton
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.MenuDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import com.nativephp.mobile.ui.MaterialIcon

/**
 * The widest a menu row gets: M3 caps `DropdownMenuItem` at 280dp, and the
 * headings and button rows built here stay inside the same width so a long
 * label wraps or ellipsizes instead of stretching the whole menu.
 */
private val MenuMaxWidth = 280.dp

/** How a menu child renders, read from the props `NavAction` sets on it. */
private enum class MenuEntry { Divider, Section, Row, Submenu, Item }

private fun NativeUINode.menuChildren(): List<NativeUINode> =
    children.filter { it.type == "top_bar_action" }

private fun NativeUINode.menuEntry(): MenuEntry = when {
    props.getBool("divider") -> MenuEntry.Divider
    props.getBool("section") -> MenuEntry.Section
    props.getBool("row") -> MenuEntry.Row
    menuChildren().isNotEmpty() -> MenuEntry.Submenu
    else -> MenuEntry.Item
}

private fun MenuEntry.isGroup(): Boolean = this == MenuEntry.Section || this == MenuEntry.Row

/** What an open submenu is remembered by: its `id`, else its label. */
private fun NativeUINode.menuKey(): String =
    props.getString("id").ifEmpty { props.getString("label") }

/**
 * The pull-down menu behind a toolbar action whose `NavAction::items()`
 * produced `top_bar_action` children. Material 3 1.4 has no menu groups or
 * submenus, so the iOS menu structure is built from plain menu pieces:
 *
 * - `section`: its children inline, under an optional small heading, with
 *   a divider between it and the items around it.
 * - `row`: its children side by side as equal-width icon-over-label
 *   buttons, like the quick actions across the top of Chrome's menu.
 * - any other item with children is a submenu. Tapping it swaps the menu's
 *   contents for the submenu's under a back row, which is how the
 *   platform's own `PopupMenu` shows submenus.
 */
@Composable
internal fun TopBarActionMenu(
    action: NativeUINode,
    items: List<NativeUINode>,
    icon: String,
    a11yLabel: String,
    disabled: Boolean,
) {
    var expanded by remember { mutableStateOf(false) }
    // Keys of the submenus drilled into, outermost first. Empty is the top level.
    val path = remember { mutableStateListOf<String>() }
    val scroll = rememberScrollState()

    val levels = resolveSubmenus(items, path)
    val current = levels.lastOrNull()

    val close = { expanded = false }
    val openSubmenu: (NativeUINode) -> Unit = { submenu ->
        // Drop any keys past the level on screen (see resolveSubmenus) so
        // the new one lands directly under it.
        path.removeRange(levels.size, path.size)
        path.add(submenu.menuKey())
    }
    val goBack = { path.removeRange(levels.size - 1, path.size) }

    // Each level starts at its top. Without this a long submenu opens
    // wherever the parent list happened to be scrolled to. Skipped on
    // close so a scrolled list doesn't jump while it fades out.
    LaunchedEffect(expanded, levels.size) {
        if (expanded) {
            scroll.scrollTo(0)
        }
    }

    // The Box makes this button the menu's anchor. Without it the popup
    // anchors to the whole actions row and can open under the first action.
    Box {
        IconButton(enabled = !disabled, onClick = {
            // Back to the top level on every open. Clearing here rather than
            // on dismiss keeps the closing fade from flashing the top level.
            path.clear()
            expanded = true
        }) {
            TopBarActionGlyph(action, icon, a11yLabel)
        }
        DropdownMenu(expanded = expanded, onDismissRequest = close, scrollState = scroll) {
            // A fresh group per level, so the tapped row's ripple doesn't
            // carry over onto whichever row takes its slot in the next level.
            key(levels.size, current?.menuKey()) {
                if (current != null) {
                    SubmenuHeader(current, goBack)
                }
                MenuEntries(current?.menuChildren() ?: items, close, openSubmenu)
            }
        }
    }
}

/**
 * The submenus [path] names, outermost first. They're looked up in the live
 * tree on every composition instead of being held as node refs: a republish
 * while the menu is open brings new nodes with new callback ids, and a held
 * ref would fire the old ones. A key that no longer resolves ends the walk,
 * so the menu stays on the deepest level that still exists.
 */
private fun resolveSubmenus(items: List<NativeUINode>, path: List<String>): List<NativeUINode> {
    val levels = mutableListOf<NativeUINode>()
    var level = items
    for (key in path) {
        val submenu = findSubmenu(level, key) ?: break
        levels += submenu
        level = submenu.menuChildren()
    }

    return levels
}

/** The submenu keyed [key] in [items], looking inside sections and rows too. */
private fun findSubmenu(items: List<NativeUINode>, key: String): NativeUINode? {
    for (item in items) {
        when (item.menuEntry()) {
            MenuEntry.Section, MenuEntry.Row -> findSubmenu(item.menuChildren(), key)?.let { return it }
            MenuEntry.Submenu -> if (item.menuKey() == key) return item
            else -> {}
        }
    }

    return null
}

/**
 * One level of the menu. Sections and rows get a divider between them and
 * whatever sits next to them, like iOS draws around inline sections. An
 * explicit `NavAction::divider()` already there stands in for it, so two
 * lines never stack.
 */
@Composable
private fun MenuEntries(
    items: List<NativeUINode>,
    onClose: () -> Unit,
    onOpenSubmenu: (NativeUINode) -> Unit,
) {
    // An empty section or row has nothing to show, and shouldn't leave a
    // stray divider behind either.
    val entries = items.filter { !it.menuEntry().isGroup() || it.menuChildren().isNotEmpty() }
    var previous: MenuEntry? = null

    for (item in entries) {
        val entry = item.menuEntry()
        val before = previous
        if (before != null && before != MenuEntry.Divider && entry != MenuEntry.Divider &&
            (entry.isGroup() || before.isGroup())
        ) {
            HorizontalDivider()
        }

        when (entry) {
            MenuEntry.Divider -> HorizontalDivider()
            MenuEntry.Section -> MenuSection(item, onClose, onOpenSubmenu)
            MenuEntry.Row -> MenuButtonRow(item, onClose, onOpenSubmenu)
            MenuEntry.Submenu -> MenuItem(item, isSubmenu = true) { onOpenSubmenu(item) }
            MenuEntry.Item -> MenuItem(item, isSubmenu = false) { activate(item, onClose) }
        }
        previous = entry
    }
}

/** A leaf item was picked: close the menu, then tell PHP. */
private fun activate(item: NativeUINode, onClose: () -> Unit) {
    onClose()
    if (item.onPress != 0) {
        NativeElementBridge.sendPressEvent(item.onPress, item.id)
    }
}

/**
 * A `section`: its label as a small heading, then its children inline.
 * The heading sits at a menu item's own 12dp inset so it lines up with the
 * icons below it.
 */
@Composable
private fun MenuSection(
    section: NativeUINode,
    onClose: () -> Unit,
    onOpenSubmenu: (NativeUINode) -> Unit,
) {
    val label = section.props.getString("label")
    if (label.isNotEmpty()) {
        Text(
            label,
            style = MaterialTheme.typography.labelLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier
                .widthIn(max = MenuMaxWidth)
                .padding(horizontal = 12.dp, vertical = 8.dp)
                .semantics { heading() },
        )
    }
    MenuEntries(section.menuChildren(), onClose, onOpenSubmenu)
}

/**
 * A plain item or a submenu's entry: label, optional `subtitle` line,
 * leading icon, and a trailing chevron (submenu) or check (`checked`).
 */
@Composable
private fun MenuItem(item: NativeUINode, isSubmenu: Boolean, onClick: () -> Unit) {
    val label = item.props.getString("label")
    val subtitle = item.props.getString("subtitle")
    val icon = item.props.getString("icon")
    val enabled = !item.props.getBool("disabled")
    val checked = item.props.getBool("checked")

    // `destructive()` tints the whole row, text and icons, to mirror
    // SwiftUI's `Button(role: .destructive)` which colours both. A red label
    // beside a default-tinted icon scans as a styling glitch. Doing it
    // through the item colours instead of tinting each glyph means a
    // destructive item that's also disabled still gets M3's disabled tone.
    val colors = if (item.props.getBool("destructive")) {
        val error = MaterialTheme.colorScheme.error
        MenuDefaults.itemColors(textColor = error, leadingIconColor = error, trailingIconColor = error)
    } else {
        MenuDefaults.itemColors()
    }

    DropdownMenuItem(
        // The check mark is decorative, so say it to TalkBack this way.
        modifier = if (checked) Modifier.semantics { selected = true } else Modifier,
        enabled = enabled,
        colors = colors,
        // The stock padding has no vertical room, which is fine for one line
        // in a 48dp row but cramps a subtitle against the row's edges.
        contentPadding = if (subtitle.isNotEmpty()) {
            PaddingValues(horizontal = 12.dp, vertical = 8.dp)
        } else {
            MenuDefaults.DropdownMenuItemContentPadding
        },
        text = { MenuItemText(item, label, subtitle, enabled) },
        leadingIcon = if (icon.isNotEmpty()) {
            { MaterialIcon(name = icon, contentDescription = null) }
        } else null,
        trailingIcon = when {
            isSubmenu -> {
                { MaterialIcon(name = "chevron_right", contentDescription = null, modifier = mirroredInRtl()) }
            }
            checked -> {
                { MaterialIcon(name = "check", contentDescription = null) }
            }
            else -> null
        },
        onClick = onClick,
    )
}

/**
 * The label, with the `subtitle` under it in a smaller, quieter style. The
 * label takes the item's own colour (error, disabled) from LocalContentColor.
 * The subtitle sets its own colour, so it has to fade itself when disabled.
 */
@Composable
private fun MenuItemText(item: NativeUINode, label: String, subtitle: String, enabled: Boolean) {
    val labelModifier = a11yLabelOverride(item)
    if (subtitle.isEmpty()) {
        Text(label, modifier = labelModifier)
    } else {
        val subtitleColor = when {
            item.props.getBool("destructive") -> LocalContentColor.current
            enabled -> MaterialTheme.colorScheme.onSurfaceVariant
            else -> MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.38f)
        }
        Column {
            Text(label, modifier = labelModifier)
            Text(subtitle, style = MaterialTheme.typography.bodySmall, color = subtitleColor)
        }
    }
}

/**
 * The open submenu's title with a back arrow, returning to the parent
 * level. System back still closes the whole menu, as it does for a
 * `PopupMenu` submenu.
 */
@Composable
private fun SubmenuHeader(submenu: NativeUINode, onBack: () -> Unit) {
    DropdownMenuItem(
        text = {
            Text(
                submenu.props.getString("label"),
                fontWeight = FontWeight.SemiBold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        },
        leadingIcon = {
            MaterialIcon(name = "arrow_back", contentDescription = "Back", modifier = mirroredInRtl())
        },
        onClick = onBack,
    )
    HorizontalDivider()
}

/**
 * A `row`: its children side by side as equal-width buttons, each an icon
 * over a short label. A child with children of its own opens as a submenu.
 */
@Composable
private fun MenuButtonRow(
    row: NativeUINode,
    onClose: () -> Unit,
    onOpenSubmenu: (NativeUINode) -> Unit,
) {
    Row(
        // widthIn before fillMaxWidth so the cap also limits the width the
        // row asks the menu for, not just the width it takes.
        modifier = Modifier
            .widthIn(max = MenuMaxWidth)
            .fillMaxWidth()
            .padding(horizontal = 8.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        row.menuChildren()
            .filter { it.menuEntry() != MenuEntry.Divider }
            .forEach { button ->
                MenuRowButton(button, Modifier.weight(1f)) {
                    if (button.menuEntry() == MenuEntry.Submenu) {
                        onOpenSubmenu(button)
                    } else {
                        activate(button, onClose)
                    }
                }
            }
    }
}

/**
 * One button in a `row`. There's no room for a trailing check, so a
 * `checked` button sits on a tonal fill instead, like a selected segment.
 * Colours follow a menu item's: onSurface text, onSurfaceVariant icon,
 * error for `destructive`, and M3's 38% onSurface when disabled.
 */
@Composable
private fun MenuRowButton(item: NativeUINode, modifier: Modifier, onClick: () -> Unit) {
    val label = item.props.getString("label")
    val icon = item.props.getString("icon")
    val enabled = !item.props.getBool("disabled")
    val checked = item.props.getBool("checked")
    val scheme = MaterialTheme.colorScheme

    val (textColor, iconColor) = when {
        !enabled -> scheme.onSurface.copy(alpha = 0.38f).let { it to it }
        item.props.getBool("destructive") -> scheme.error to scheme.error
        checked -> scheme.onSecondaryContainer to scheme.onSecondaryContainer
        else -> scheme.onSurface to scheme.onSurfaceVariant
    }

    Column(
        modifier = modifier
            .clip(RoundedCornerShape(12.dp))
            .then(if (checked) Modifier.background(scheme.secondaryContainer) else Modifier)
            .clickable(enabled = enabled, role = Role.Button, onClick = onClick)
            .then(if (checked) Modifier.semantics { selected = true } else Modifier)
            .padding(horizontal = 4.dp, vertical = 8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        if (icon.isNotEmpty()) {
            // Icon-only buttons need a name for TalkBack; with a label the
            // label (or its a11y override) already is one.
            val iconName = if (label.isEmpty()) item.props.getString("a11y_label").ifEmpty { null } else null
            MaterialIcon(name = icon, contentDescription = iconName, tint = iconColor)
        }
        if (label.isNotEmpty()) {
            if (icon.isNotEmpty()) {
                Spacer(Modifier.height(4.dp))
            }
            Text(
                label,
                style = MaterialTheme.typography.labelMedium,
                color = textColor,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = a11yLabelOverride(item),
            )
        }
    }
}

/**
 * `a11y_label` (NavAction::a11yLabel()) replaces what TalkBack reads for a
 * visible label. Applied to the label's Text, the tappable row around it
 * still merges it in and keeps its button role and click action.
 */
private fun a11yLabelOverride(item: NativeUINode): Modifier {
    val a11yLabel = item.props.getString("a11y_label")

    return if (a11yLabel.isNotEmpty()) {
        Modifier.clearAndSetSemantics { contentDescription = a11yLabel }
    } else {
        Modifier
    }
}

/** Font glyphs don't auto-mirror like AutoMirrored ImageVectors; flip directional ones for RTL. */
@Composable
private fun mirroredInRtl(): Modifier =
    if (LocalLayoutDirection.current == LayoutDirection.Rtl) {
        Modifier.scale(scaleX = -1f, scaleY = 1f)
    } else {
        Modifier
    }
