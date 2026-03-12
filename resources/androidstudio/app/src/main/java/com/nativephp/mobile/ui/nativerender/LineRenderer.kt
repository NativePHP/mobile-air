package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.view.View

class LineViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        return LineView(context, node)
    }

    override fun updateView(view: View, node: NativeUINode) {
        (view as? LineView)?.updateNode(node)
    }
}

private class LineView(context: Context, private var node: NativeUINode) : View(context) {
    private val paint = Paint(Paint.ANTI_ALIAS_FLAG)
    private val density = context.resources.displayMetrics.density

    fun updateNode(newNode: NativeUINode) {
        node = newNode
        invalidate()
    }

    override fun onDraw(canvas: Canvas) {
        val p = node.props
        val fromX = p.getFloat("from_x", 0f)
        val fromY = p.getFloat("from_y", 0f)
        val toX = p.getFloat("to_x", 0f)
        val toY = p.getFloat("to_y", 0f)

        val style = node.style
        paint.color = if (style != null && style.borderColor != 0) style.borderColor else Color.BLACK
        paint.strokeWidth = if (style != null && style.borderWidth > 0) style.borderWidth * density else density

        canvas.drawLine(
            fromX * density,
            fromY * density,
            toX * density,
            toY * density,
            paint
        )
    }
}
