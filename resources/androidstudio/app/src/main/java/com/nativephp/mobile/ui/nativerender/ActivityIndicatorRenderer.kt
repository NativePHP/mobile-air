package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.content.res.ColorStateList
import android.view.View
import android.widget.ProgressBar

class ActivityIndicatorViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val style = resolveStyle(node)
        val pb = ProgressBar(context, null, style)
        pb.isIndeterminate = true
        applyProps(pb, node)
        return pb
    }

    override fun updateView(view: View, node: NativeUINode) {
        val pb = view as? ProgressBar ?: return
        applyProps(pb, node)
    }

    private fun applyProps(pb: ProgressBar, node: NativeUINode) {
        val color = node.props.getColor("color", 0)
        if (color != 0) {
            pb.indeterminateTintList = ColorStateList.valueOf(color)
        }
    }

    private fun resolveStyle(node: NativeUINode): Int {
        return when (node.props.getInt("size")) {
            1 -> android.R.attr.progressBarStyleLarge
            2 -> android.R.attr.progressBarStyleSmall
            else -> android.R.attr.progressBarStyle
        }
    }
}
