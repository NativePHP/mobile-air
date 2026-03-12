package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.view.View
import android.widget.FrameLayout

class CanvasViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val frame = FrameLayout(context)
        frame.clipChildren = true
        frame.clipToPadding = true
        return frame
    }

    override fun updateView(view: View, node: NativeUINode) {
        // Children are handled by the core renderer's reconcileChildren
    }
}
