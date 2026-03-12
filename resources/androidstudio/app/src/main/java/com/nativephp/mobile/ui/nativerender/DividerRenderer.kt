package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.graphics.Color
import android.view.View

class DividerViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val v = View(context)
        v.setBackgroundColor(Color.parseColor("#E0E0E0"))
        return v
    }

    override fun updateView(view: View, node: NativeUINode) {
        // Divider is just a styled line — color handled by applyStyle or default
    }
}
