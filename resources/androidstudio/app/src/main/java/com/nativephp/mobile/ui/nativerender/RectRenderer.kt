package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.view.View

class RectViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        return View(context)
    }

    override fun updateView(view: View, node: NativeUINode) {
        // Styled by applyStyle in the core renderer
    }
}
