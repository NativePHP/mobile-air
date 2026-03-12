package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.content.res.ColorStateList
import android.content.res.Configuration
import android.graphics.Color
import android.view.Gravity
import android.view.View
import android.widget.LinearLayout
import android.widget.Switch
import android.widget.TextView

class ToggleViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val p = node.props
        val label = p.getString("label")

        if (label.isEmpty()) {
            return createSwitch(context, node)
        }

        val container = LinearLayout(context)
        container.orientation = LinearLayout.HORIZONTAL
        container.gravity = Gravity.CENTER_VERTICAL

        val labelView = TextView(context)
        labelView.text = label
        labelView.textSize = 16f
        applyLabelColor(labelView, node)
        labelView.layoutParams = LinearLayout.LayoutParams(
            0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f
        )
        container.addView(labelView)

        val switch = createSwitch(context, node)
        switch.layoutParams = LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.WRAP_CONTENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        )
        container.addView(switch)

        return container
    }

    override fun updateView(view: View, node: NativeUINode) {
        val p = node.props
        // Clear background — bg-* is used for toggle tint, not background color
        view.background = null

        if (view is NativeSwitch) {
            applySwitch(view, node)
        } else if (view is LinearLayout && view.childCount >= 2) {
            val labelView = view.getChildAt(0) as? TextView
            labelView?.text = p.getString("label")
            if (labelView != null) applyLabelColor(labelView, node)

            val switch = view.getChildAt(1) as? NativeSwitch
            if (switch != null) applySwitch(switch, node)
        }
    }

    private fun applyLabelColor(tv: TextView, node: NativeUINode) {
        val dark = (tv.context.resources.configuration.uiMode and
            Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES

        // text-* classes → color prop, dark:text-* → dark_color prop
        val darkColor = if (dark) node.props.getColor("dark_color", 0) else 0
        val color = node.props.getColor("color", 0)
        val labelColor = node.props.getColor("label_color", 0)

        val effective = when {
            darkColor != 0 -> darkColor
            labelColor != 0 -> labelColor
            color != 0 -> color
            else -> if (dark) Color.WHITE else Color.BLACK
        }
        tv.setTextColor(effective)
    }

    private fun createSwitch(context: Context, node: NativeUINode): NativeSwitch {
        val switch = NativeSwitch(context)
        applySwitch(switch, node)
        return switch
    }

    private fun applySwitch(switch: NativeSwitch, node: NativeUINode) {
        val p = node.props
        switch.nodeId = node.id
        switch.onChangeCb = p.getCallbackId("on_change")

        switch.setOnCheckedChangeListener(null)
        switch.isChecked = p.getBool("value")
        switch.isEnabled = !p.getBool("disabled")

        // bg-* classes → style.bgColor for toggle tint
        val tintColor = node.style?.bgColor ?: 0
        val alpha = (tintColor.toLong() and 0xFF000000L) ushr 24
        if (tintColor != 0 && alpha != 0L) {
            switch.thumbTintList = ColorStateList(
                arrayOf(intArrayOf(android.R.attr.state_checked), intArrayOf()),
                intArrayOf(tintColor, Color.LTGRAY)
            )
            val trackOn = blendColor(tintColor, Color.WHITE, 0.5f)
            switch.trackTintList = ColorStateList(
                arrayOf(intArrayOf(android.R.attr.state_checked), intArrayOf()),
                intArrayOf(trackOn, Color.parseColor("#E0E0E0"))
            )
        }

        switch.setOnCheckedChangeListener { _, isChecked ->
            if (switch.onChangeCb != 0) {
                NativeElementBridge.sendToggleChangeEvent(switch.onChangeCb, switch.nodeId, isChecked)
            }
        }
    }

    private fun blendColor(c1: Int, c2: Int, ratio: Float): Int {
        val r = (Color.red(c1) * (1 - ratio) + Color.red(c2) * ratio).toInt()
        val g = (Color.green(c1) * (1 - ratio) + Color.green(c2) * ratio).toInt()
        val b = (Color.blue(c1) * (1 - ratio) + Color.blue(c2) * ratio).toInt()
        return Color.rgb(r, g, b)
    }
}

@Suppress("DEPRECATION")
private class NativeSwitch(context: Context) : Switch(context) {
    var nodeId: Int = 0
    var onChangeCb: Int = 0
}
