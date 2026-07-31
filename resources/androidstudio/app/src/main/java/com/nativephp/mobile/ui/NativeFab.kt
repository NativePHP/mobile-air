package com.nativephp.mobile.ui

import android.util.Log
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.unit.dp

private const val TAG = "NativeFab"

/**
 * Parse hex color string to Compose Color
 * Supports formats: #RGB, #RRGGBB, #AARRGGBB
 */
private fun parseColor(colorString: String?): Color? {
    if (colorString.isNullOrBlank()) return null

    return try {
        val hex = colorString.trim().removePrefix("#")
        when (hex.length) {
            3 -> {
                // #RGB -> #RRGGBB
                val r = hex[0].toString().repeat(2)
                val g = hex[1].toString().repeat(2)
                val b = hex[2].toString().repeat(2)
                Color(android.graphics.Color.parseColor("#$r$g$b"))
            }
            6 -> {
                // #RRGGBB
                Color(android.graphics.Color.parseColor("#$hex"))
            }
            8 -> {
                // #AARRGGBB
                Color(android.graphics.Color.parseColor("#$hex"))
            }
            else -> {
                Log.w(TAG, "Invalid color format: $colorString")
                null
            }
        }
    } catch (e: Exception) {
        Log.e(TAG, "Failed to parse color: $colorString", e)
        null
    }
}

/**
 * Dynamic Floating Action Button that renders from Laravel NativeUI state
 *
 * Supports different FAB sizes:
 * - "small": SmallFloatingActionButton
 * - "regular": FloatingActionButton (default)
 * - "large": LargeFloatingActionButton
 * - "extended": ExtendedFloatingActionButton with label
 */
@Composable
fun NativeFab(
    onNavigate: (String) -> Unit,
    onEvent: (String) -> Unit
) {
    val fabData by NativeUIState.fabData

    if (fabData == null) {
        return  // Don't render if no data
    }

    val data = fabData ?: return

    Log.d(TAG, "🎨 Rendering FAB: size=${data.size}, icon=${data.icon}, label=${data.label}")

    val onClick: () -> Unit = {
        when {
            data.url != null -> {
                Log.d(TAG, "🖱️ FAB clicked with URL: ${data.url}")
                onNavigate(data.url)
            }
            data.event != null -> {
                Log.d(TAG, "🖱️ FAB clicked with event: ${data.event}")
                onEvent(data.event)
            }
            else -> {
                Log.w(TAG, "⚠️ FAB clicked but no URL or event specified")
            }
        }
    }

    // Parse custom colors if provided
    val containerColor = parseColor(data.containerColor) ?: FloatingActionButtonDefaults.containerColor
    val contentColor = parseColor(data.contentColor) ?: contentColorFor(containerColor)

    // Apply bottom offset if specified
    val modifier = if (data.bottomOffset != null && data.bottomOffset > 0) {
        Modifier.padding(bottom = data.bottomOffset.dp)
    } else {
        Modifier
    }

    // Apply custom elevation if specified
    val elevation = if (data.elevation != null) {
        FloatingActionButtonDefaults.elevation(
            defaultElevation = data.elevation.dp,
            pressedElevation = (data.elevation + 2).dp,
            focusedElevation = (data.elevation + 2).dp,
            hoveredElevation = (data.elevation + 2).dp
        )
    } else {
        FloatingActionButtonDefaults.elevation()
    }

    // Apply custom shape if specified
    val shape: Shape = if (data.cornerRadius != null) {
        RoundedCornerShape(data.cornerRadius.dp)
    } else {
        // Default to circle for small/regular/large, rounded for extended
        if (data.size?.lowercase() == "extended") {
            FloatingActionButtonDefaults.extendedFabShape
        } else {
            CircleShape
        }
    }

    when (data.size?.lowercase()) {
        "small" -> {
            SmallFloatingActionButton(
                onClick = onClick,
                modifier = modifier,
                shape = shape,
                containerColor = containerColor,
                contentColor = contentColor,
                elevation = elevation
            ) {
                MaterialIcon(
                    name = data.icon,
                    contentDescription = data.label ?: data.icon
                )
            }
        }
        "large" -> {
            LargeFloatingActionButton(
                onClick = onClick,
                modifier = modifier,
                shape = shape,
                containerColor = containerColor,
                contentColor = contentColor,
                elevation = elevation
            ) {
                MaterialIcon(
                    name = data.icon,
                    contentDescription = data.label ?: data.icon
                )
            }
        }
        "extended" -> {
            ExtendedFloatingActionButton(
                onClick = onClick,
                modifier = modifier,
                shape = shape,
                containerColor = containerColor,
                contentColor = contentColor,
                elevation = elevation,
                icon = {
                    MaterialIcon(
                        name = data.icon,
                        contentDescription = data.label ?: data.icon
                    )
                },
                text = {
                    if (data.label != null) {
                        Text(data.label)
                    }
                }
            )
        }
        else -> {
            // Default: regular FAB
            FloatingActionButton(
                onClick = onClick,
                modifier = modifier,
                shape = shape,
                containerColor = containerColor,
                contentColor = contentColor,
                elevation = elevation
            ) {
                MaterialIcon(
                    name = data.icon,
                    contentDescription = data.label ?: data.icon
                )
            }
        }
    }
}