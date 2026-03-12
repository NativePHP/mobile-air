package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.graphics.PorterDuff
import android.graphics.PorterDuffColorFilter
import android.view.View
import android.widget.ImageView
import coil3.ImageLoader
import coil3.request.ImageRequest
import coil3.target.ImageViewTarget

class ImageViewRenderer : NativeViewRenderer {
    override fun createView(context: Context, node: NativeUINode): View {
        val iv = ImageView(context)
        iv.clipToOutline = true
        applyProps(iv, node)
        return iv
    }

    override fun updateView(view: View, node: NativeUINode) {
        val iv = view as? ImageView ?: return
        applyProps(iv, node)
    }

    private fun applyProps(iv: ImageView, node: NativeUINode) {
        val p = node.props
        val src = p.getString("src")
        val fit = p.getInt("fit")
        val tintColor = p.getColor("tint_color", 0)

        iv.scaleType = when (fit) {
            0 -> ImageView.ScaleType.CENTER
            1 -> ImageView.ScaleType.FIT_CENTER
            2 -> ImageView.ScaleType.CENTER_CROP
            3 -> ImageView.ScaleType.FIT_XY
            4 -> ImageView.ScaleType.FIT_CENTER
            else -> ImageView.ScaleType.FIT_CENTER
        }

        if (tintColor != 0) {
            iv.colorFilter = PorterDuffColorFilter(tintColor, PorterDuff.Mode.SRC_IN)
        } else {
            iv.colorFilter = null
        }

        if (src.isNotEmpty()) {
            val request = ImageRequest.Builder(iv.context)
                .data(src)
                .target(ImageViewTarget(iv))
                .build()
            ImageLoader(iv.context).enqueue(request)
        }
    }
}
