package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp

private enum class AuthMode { Login, Register, Recover }

@Composable
fun AuthFlow(state: VisitorUiState, viewModel: AppViewModel) {
    var mode by remember { mutableStateOf(AuthMode.Login) }
    val showMode: (AuthMode) -> Unit = {
        viewModel.clearBanner()
        mode = it
    }
    Box(Modifier.fillMaxSize().background(AppBackground)) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .imePadding()
                .navigationBarsPadding(),
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .background(Color.White)
                    .statusBarsPadding()
                    .padding(horizontal = 20.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                if (mode != AuthMode.Login) {
                    IconButton(onClick = { showMode(AuthMode.Login) }) {
                        Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back to sign in")
                    }
                }
                PortalBrand(
                    modifier = Modifier.weight(1f),
                    subtitle = if (mode == AuthMode.Login) "Visitor mobile application" else null,
                )
            }

            Column(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 26.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(22.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
                ) {
                    Column(
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 22.dp, vertical = 26.dp),
                        verticalArrangement = Arrangement.spacedBy(14.dp),
                    ) {
                        state.error?.let { MessageCard(it, true, viewModel::clearBanner) }
                        state.message?.let { MessageCard(it, false, viewModel::clearBanner) }
                        when (mode) {
                            AuthMode.Login -> LoginForm(
                                busy = state.busy,
                                onLogin = viewModel::login,
                                onRegister = { showMode(AuthMode.Register) },
                                onRecover = { showMode(AuthMode.Recover) },
                            )
                            AuthMode.Register -> RegisterForm(
                                busy = state.busy,
                                onRegister = viewModel::register,
                            )
                            AuthMode.Recover -> RecoverForm(
                                busy = state.busy,
                                onRequest = viewModel::requestPasswordReset,
                                onReset = viewModel::resetPassword,
                            )
                        }
                        if (state.busy) LinearProgressIndicator(Modifier.fillMaxWidth(), color = IsatuBlue)
                    }
                }
                Spacer(Modifier.height(18.dp))
                Text(
                    "Your visit notifications and visitor passes will appear inside this app.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MutedInk,
                    textAlign = TextAlign.Center,
                )
            }
        }
    }
}

@Composable
private fun ColumnScope.LoginForm(
    busy: Boolean,
    onLogin: (String, String) -> Unit,
    onRegister: () -> Unit,
    onRecover: () -> Unit,
) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }

    AuthHeading("LOG IN", "Welcome back. Sign in to manage your campus visits.")
    PortalTextField(
        value = email,
        onValueChange = { email = it },
        label = "Email address",
        icon = { Icon(Icons.Rounded.Email, contentDescription = null) },
        keyboardType = KeyboardType.Email,
    )
    PasswordField(password, { password = it }, visible, { visible = !visible }, "Password")
    TextButton(onClick = onRecover, modifier = Modifier.align(Alignment.End)) {
        Text("Forgot password?")
    }
    PrimaryButton(
        "Log in",
        { onLogin(email, password) },
        Modifier.fillMaxWidth(),
        !busy && email.isNotBlank() && password.isNotBlank(),
    )
    HorizontalDivider(color = BorderSoft)
    Row(Modifier.align(Alignment.CenterHorizontally), verticalAlignment = Alignment.CenterVertically) {
        Text("New visitor?", color = MutedInk)
        TextButton(onClick = onRegister) { Text("Create account") }
    }
}

@Composable
private fun ColumnScope.RegisterForm(
    busy: Boolean,
    onRegister: (String, String, String, String) -> Unit,
) {
    var name by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var contact by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    var trackingConsent by remember { mutableStateOf(false) }

    AuthHeading("CREATE ACCOUNT", "Register once, then request and monitor your visits in the app.")
    PortalTextField(name, { name = it }, "Full name", { Icon(Icons.Rounded.Person, null) })
    PortalTextField(email, { email = it }, "Email address", { Icon(Icons.Rounded.Email, null) }, KeyboardType.Email)
    PortalTextField(contact, { contact = it }, "Contact number", { Icon(Icons.Rounded.Phone, null) }, KeyboardType.Phone)
    PasswordField(password, { password = it }, visible, { visible = !visible }, "Password (at least 10 characters)")
    Row(verticalAlignment = Alignment.Top) {
        Checkbox(checked = trackingConsent, onCheckedChange = { trackingConsent = it })
        Text(
            "I agree to the Visitor Consent Notice, including location sharing only after Security checks me in and only until my visit ends.",
            modifier = Modifier.padding(top = 11.dp),
            style = MaterialTheme.typography.bodySmall,
            color = MutedInk,
        )
    }
    PrimaryButton(
        "Create account",
        { onRegister(email, name, contact, password) },
        Modifier.fillMaxWidth(),
        !busy && name.trim().length >= 2 && email.isNotBlank() && password.length >= 10 && trackingConsent,
    )
    Text(
        "After signup, you will be signed in immediately. Visit decisions and reminders are delivered through app notifications.",
        style = MaterialTheme.typography.bodySmall,
        color = MutedInk,
        textAlign = TextAlign.Center,
    )
}

@Composable
private fun ColumnScope.RecoverForm(
    busy: Boolean,
    onRequest: (String) -> Unit,
    onReset: (String, String, String) -> Unit,
) {
    var email by remember { mutableStateOf("") }
    var token by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }

    AuthHeading("RESET PASSWORD", "Request a secure recovery code, then create a new password.")
    PortalTextField(email, { email = it }, "Email address", { Icon(Icons.Rounded.Email, null) }, KeyboardType.Email)
    PrimaryButton("Request recovery code", { onRequest(email) }, Modifier.fillMaxWidth(), !busy && email.isNotBlank())
    HorizontalDivider(color = BorderSoft)
    PortalTextField(token, { token = it.trim() }, "Recovery code", { Icon(Icons.Rounded.VerifiedUser, null) })
    PasswordField(password, { password = it }, visible, { visible = !visible }, "New password")
    PrimaryButton(
        "Save new password",
        { onReset(email, token, password) },
        Modifier.fillMaxWidth(),
        !busy && email.isNotBlank() && token.isNotBlank() && password.length >= 10,
    )
}

@Composable
private fun AuthHeading(title: String, body: String) {
    Column(
        modifier = Modifier.fillMaxWidth(),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Text(title, style = MaterialTheme.typography.headlineMedium, color = IsatuBlueDark)
        Text(body, color = MutedInk, textAlign = TextAlign.Center)
    }
}

@Composable
private fun PortalTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    icon: @Composable () -> Unit,
    keyboardType: KeyboardType = KeyboardType.Text,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        leadingIcon = icon,
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
        colors = OutlinedTextFieldDefaults.colors(
            focusedBorderColor = IsatuBlue,
            focusedLabelColor = IsatuBlue,
            unfocusedBorderColor = BorderSoft,
        ),
    )
}

@Composable
private fun PasswordField(
    value: String,
    onValueChange: (String) -> Unit,
    visible: Boolean,
    onToggle: () -> Unit,
    label: String,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        leadingIcon = { Icon(Icons.Rounded.Lock, contentDescription = null) },
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
        visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
        trailingIcon = {
            IconButton(onClick = onToggle) {
                Icon(
                    if (visible) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility,
                    contentDescription = if (visible) "Hide password" else "Show password",
                )
            }
        },
        colors = OutlinedTextFieldDefaults.colors(
            focusedBorderColor = IsatuBlue,
            focusedLabelColor = IsatuBlue,
            unfocusedBorderColor = BorderSoft,
        ),
    )
}
