package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.view.View

class CircleViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        return View(context)
    }

    override fun updateView(view: View, node: NativeUINode) {
        // Circle shape is achieved via borderRadius=9999 in applyStyle
    }
}
