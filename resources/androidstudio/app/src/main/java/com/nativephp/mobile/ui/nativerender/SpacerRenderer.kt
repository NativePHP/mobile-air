package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.view.View

class SpacerViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        return View(context)
    }

    override fun updateView(view: View, node: NativeUINode) {
        // No-op — spacer is just an empty view sized by Yoga
    }
}
