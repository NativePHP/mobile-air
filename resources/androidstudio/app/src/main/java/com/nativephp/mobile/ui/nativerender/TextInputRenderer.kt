package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.text.Editable
import android.text.InputType
import android.text.TextWatcher
import android.view.View
import android.view.inputmethod.EditorInfo
import android.widget.EditText

class TextInputViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val et = NativeEditText(context)
        val density = context.resources.displayMetrics.density
        val hPad = (12 * density).toInt()
        val vPad = (8 * density).toInt()
        et.setPadding(hPad, vPad, hPad, vPad)
        et.textSize = 16f
        et.setTextColor(Color.BLACK)
        et.setHintTextColor(Color.GRAY)

        // Rounded border background
        val border = GradientDrawable()
        border.setColor(Color.WHITE)
        border.cornerRadius = 8 * density
        border.setStroke((1 * density).toInt(), Color.parseColor("#CCCCCC"))
        et.background = border

        applyProps(et, node)
        return et
    }

    override fun updateView(view: View, node: NativeUINode) {
        val et = view as? NativeEditText ?: return
        // Don't overwrite text if user is actively editing
        if (!et.hasFocus()) {
            val newValue = node.props.getString("value")
            if (et.text.toString() != newValue) {
                et.setText(newValue)
                et.setSelection(newValue.length)
            }
        }
        et.nodeId = node.id
        applyProps(et, node)

        // Re-apply border background (applyStyle may have overwritten it)
        val density = view.context.resources.displayMetrics.density
        val border = GradientDrawable()
        border.setColor(Color.WHITE)
        border.cornerRadius = 8 * density
        border.setStroke((1 * density).toInt(), Color.parseColor("#CCCCCC"))
        et.background = border
    }

    private fun applyProps(et: NativeEditText, node: NativeUINode) {
        val p = node.props
        et.hint = p.getString("placeholder")
        et.nodeId = node.id
        et.onChangeCb = p.getCallbackId("on_change")
        et.onSubmitCb = p.getCallbackId("on_submit")

        val secure = p.getBool("secure")
        val multiline = p.getBool("multiline")
        et.inputType = when {
            secure -> InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            multiline -> InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_MULTI_LINE
            else -> InputType.TYPE_CLASS_TEXT
        }
        et.isSingleLine = !multiline
        // Explicitly set transformation — inputType alone doesn't always apply it
        et.transformationMethod = if (secure) {
            android.text.method.PasswordTransformationMethod.getInstance()
        } else {
            null
        }

        et.imeOptions = if (et.onSubmitCb != 0) EditorInfo.IME_ACTION_DONE else EditorInfo.IME_ACTION_UNSPECIFIED

        // Set initial value only on first setup
        if (et.text.isNullOrEmpty()) {
            val value = p.getString("value")
            if (value.isNotEmpty()) {
                et.setText(value)
                et.setSelection(value.length)
            }
        }
    }
}

class NativeEditText(context: Context) : EditText(context) {
    var nodeId: Int = 0
    var onChangeCb: Int = 0
    var onSubmitCb: Int = 0

    private val textWatcher = object : TextWatcher {
        override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
        override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
        override fun afterTextChanged(s: Editable?) {
            if (onChangeCb != 0) {
                NativeElementBridge.sendTextChangeEvent(onChangeCb, nodeId, s?.toString() ?: "")
            }
        }
    }

    init {
        addTextChangedListener(textWatcher)
        setOnEditorActionListener { _, actionId, _ ->
            if (actionId == EditorInfo.IME_ACTION_DONE && onSubmitCb != 0) {
                NativeElementBridge.sendSubmitEvent(onSubmitCb, nodeId, text?.toString() ?: "")
                true
            } else {
                false
            }
        }
    }
}
