package com.nativephp.mobile.bridge.plugins

import com.nativephp.mobile.ui.nativerender.NativeRendererRegistry
import com.nativephp.mobile.ui.nativerender.NodeRenderer
import com.nativephp.plugins.native_ui.ui.*

// AUTO-GENERATED FILE - DO NOT EDIT
// Generated from installed NativePHP UI plugins

fun registerPluginRenderers() {
    // Plugin: native-ui — Container types
    NativeRendererRegistry.register("column", NodeRenderer { node, modifier ->
        ColumnRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("row", NodeRenderer { node, modifier ->
        RowRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("stack", NodeRenderer { node, modifier ->
        StackRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("scroll_view", NodeRenderer { node, modifier ->
        ScrollViewRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("pressable", NodeRenderer { node, modifier ->
        PressableRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("canvas", NodeRenderer { node, modifier ->
        CanvasRenderer.Render(node, modifier)
    })

    // Plugin: native-ui — Core leaf types (new renderers)
    NativeRendererRegistry.register("text", NodeRenderer { node, modifier ->
        TextRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("image", NodeRenderer { node, modifier ->
        ImageRenderer.Render(node, modifier)
    })

    // Plugin: native-ui — Simple types (new renderers)
    NativeRendererRegistry.register("spacer", NodeRenderer { node, modifier ->
        SpacerRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("divider", NodeRenderer { node, modifier ->
        DividerRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("rect", NodeRenderer { node, modifier ->
        RectRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("circle", NodeRenderer { node, modifier ->
        CircleRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("line", NodeRenderer { node, modifier ->
        LineRenderer.Render(node, modifier)
    })

    // Plugin: native-ui — Existing plugin renderers (already in native-ui plugin)
    NativeRendererRegistry.register("button", NodeRenderer { node, modifier ->
        ButtonRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("icon", NodeRenderer { node, modifier ->
        IconRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("outlined_text_input", NodeRenderer { node, modifier ->
        OutlinedTextInputRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("filled_text_input", NodeRenderer { node, modifier ->
        FilledTextInputRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("bare_text_input", NodeRenderer { node, modifier ->
        BareTextInputRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("toggle", NodeRenderer { node, modifier ->
        ToggleRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("activity_indicator", NodeRenderer { node, modifier ->
        ActivityIndicatorRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("bottom_sheet", NodeRenderer { node, modifier ->
        BottomSheetRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("slider", NodeRenderer { node, modifier ->
        SliderRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("chip", NodeRenderer { node, modifier ->
        ChipRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("select", NodeRenderer { node, modifier ->
        SelectRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("radio_group", NodeRenderer { node, modifier ->
        RadioGroupRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("checkbox", NodeRenderer { node, modifier ->
        CheckboxRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("badge", NodeRenderer { node, modifier ->
        BadgeRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("list_item", NodeRenderer { node, modifier ->
        ListItemRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("progress_bar", NodeRenderer { node, modifier ->
        ProgressBarRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("tab_row", NodeRenderer { node, modifier ->
        TabRowRenderer.Render(node, modifier)
    })

    // Plugin: native-ui — Navigation chrome
    NativeRendererRegistry.register("top_bar", NodeRenderer { node, modifier ->
        TopBarRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("bottom_nav", NodeRenderer { node, modifier ->
        BottomNavRenderer.Render(node, modifier)
    })
    NativeRendererRegistry.register("side_nav", NodeRenderer { node, modifier ->
        SideNavRenderer.Render(node, modifier)
    })

    // Empty renderers — child data consumed by parent
    NativeRendererRegistry.register("top_bar_action", NodeRenderer { _, _ -> })
    NativeRendererRegistry.register("bottom_nav_item", NodeRenderer { _, _ -> })
    NativeRendererRegistry.register("side_nav_item", NodeRenderer { _, _ -> })
    NativeRendererRegistry.register("side_nav_group", NodeRenderer { _, _ -> })
    NativeRendererRegistry.register("side_nav_header", NodeRenderer { _, _ -> })
    NativeRendererRegistry.register("horizontal_divider", NodeRenderer { node, modifier ->
        DividerRenderer.Render(node, modifier)
    })
}
