package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.graphics.Typeface
import android.view.Gravity
import android.view.View
import android.widget.TextView

class ButtonViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val tv = TextView(context)
        tv.gravity = Gravity.CENTER
        applyProps(tv, node)
        return tv
    }

    override fun updateView(view: View, node: NativeUINode) {
        val tv = view as? TextView ?: return
        applyProps(tv, node)
    }

    private fun applyProps(tv: TextView, node: NativeUINode) {
        val p = node.props
        tv.text = p.getString("label")

        val fontSize = p.getFloat("font_size", 16f)
        tv.textSize = fontSize
        tv.typeface = Typeface.create(Typeface.DEFAULT, Typeface.BOLD)

        val labelColor = p.getColor("label_color", 0)
        val propColor = p.getColor("color", 0)
        val effectiveColor = when {
            labelColor != 0 -> labelColor
            propColor != 0 -> propColor
            else -> 0xFF000000.toInt()
        }
        tv.setTextColor(effectiveColor)

        val disabled = p.getBool("disabled")
        tv.alpha = if (disabled) 0.5f else 1.0f
    }
}
