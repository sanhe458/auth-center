package com.sanhe.authcenter

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.lifecycle.viewmodel.compose.viewModel
import com.sanhe.authcenter.ui.HomeScreen
import com.sanhe.authcenter.ui.LoginScreen
import com.sanhe.authcenter.ui.theme.AuthCenterTheme
import com.sanhe.authcenter.vm.AuthUiState
import com.sanhe.authcenter.vm.MainViewModel

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            AuthCenterTheme {
                AppRoot()
            }
        }
    }
}

@Composable
fun AppRoot(vm: MainViewModel = viewModel()) {
    val state by vm.authState.collectAsState()
    when (state) {
        is AuthUiState.LoggedIn -> HomeScreen(vm, state as AuthUiState.LoggedIn)
        else -> LoginScreen(vm, state)
    }
}
