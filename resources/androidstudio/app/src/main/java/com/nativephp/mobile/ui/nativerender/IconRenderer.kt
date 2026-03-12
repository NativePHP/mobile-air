package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.graphics.Typeface
import android.view.Gravity
import android.view.View
import android.widget.TextView
import com.nativephp.mobile.R
import com.nativephp.mobile.ui.getIconName

class IconViewRenderer : NativeViewRenderer {

    override fun createView(context: Context, node: NativeUINode): View {
        val tv = TextView(context)
        tv.gravity = Gravity.CENTER
        tv.typeface = getMaterialIconsTypeface(context)
        applyProps(tv, node)
        return tv
    }

    override fun updateView(view: View, node: NativeUINode) {
        val tv = view as? TextView ?: return
        applyProps(tv, node)
    }

    private fun applyProps(tv: TextView, node: NativeUINode) {
        val p = node.props
        val name = p.getString("name")
        val size = p.getFloat("size", 24f)
        val color = p.getColor("color", 0)

        tv.text = getIconName(name)
        tv.textSize = size

        if (color != 0) {
            tv.setTextColor(color)
        }
    }

    companion object {
        private var cachedTypeface: Typeface? = null

        fun getMaterialIconsTypeface(context: Context): Typeface {
            return cachedTypeface ?: run {
                val tf = context.resources.getFont(R.font.material_icons)
                cachedTypeface = tf
                tf
            }
        }
    }
}
