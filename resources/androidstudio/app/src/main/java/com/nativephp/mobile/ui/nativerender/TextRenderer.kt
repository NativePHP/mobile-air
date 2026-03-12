package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.content.res.Configuration
import android.graphics.Typeface
import android.text.TextUtils
import android.view.Gravity
import android.view.View
import android.widget.TextView

class TextViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val tv = TextView(context)
        tv.ellipsize = TextUtils.TruncateAt.END
        applyProps(tv, node)
        return tv
    }

    override fun updateView(view: View, node: NativeUINode) {
        val tv = view as? TextView ?: return
        applyProps(tv, node)
    }

    private fun applyProps(tv: TextView, node: NativeUINode) {
        val p = node.props
        tv.text = p.getString("text")

        val fontSize = p.getFloat("font_size", 16f)
        tv.textSize = fontSize

        val dark = (tv.context.resources.configuration.uiMode and
            Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
        val darkColor = if (dark) p.getColor("dark_color", 0) else 0
        val color = if (darkColor != 0) darkColor else p.getColor("color", 0xFF000000.toInt())
        tv.setTextColor(color)

        val weight = p.getInt("font_weight")
        tv.typeface = Typeface.create(Typeface.DEFAULT, resolveTypefaceStyle(weight))

        tv.gravity = resolveGravity(p.getInt("text_align")) or Gravity.CENTER_VERTICAL

        val maxLines = p.getInt("max_lines")
        tv.maxLines = if (maxLines > 0) maxLines else Int.MAX_VALUE
    }
}

private fun resolveTypefaceStyle(weight: Int): Int {
    return when (weight) {
        1, 2, 3 -> Typeface.NORMAL  // thin, light, normal
        4, 5 -> Typeface.BOLD       // medium, semibold (closest match)
        6, 7 -> Typeface.BOLD       // bold, extrabold
        else -> Typeface.NORMAL
    }
}

private fun resolveGravity(align: Int): Int {
    return when (align) {
        0 -> Gravity.START
        1 -> Gravity.CENTER
        2 -> Gravity.END
        else -> Gravity.START
    }
}
