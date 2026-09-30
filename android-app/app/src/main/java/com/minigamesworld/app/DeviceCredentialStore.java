package com.minigamesworld.app;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import java.security.SecureRandom;
import java.util.regex.Pattern;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

final class DeviceCredentialStore {
    private static final String PREFS = "mgw_android_device_secure_v1";
    private static final String PREF_CIPHERTEXT = "credential_ciphertext";
    private static final String PREF_IV = "credential_iv";
    private static final String KEY_ALIAS = "mgw_android_device_credential_key_v1";
    private static final String KEYSTORE = "AndroidKeyStore";
    private static final String TRANSFORMATION = "AES/GCM/NoPadding";
    private static final int BASE64_FLAGS = Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING;
    private static final Pattern CREDENTIAL_PATTERN = Pattern.compile("^[A-Za-z0-9_-]{43}$");

    private final SharedPreferences preferences;
    private final SecureRandom secureRandom = new SecureRandom();

    DeviceCredentialStore(Context context) {
        preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    synchronized String getOrCreate() {
        try {
            String existing = readExisting();
            if (existing != null) {
                return existing;
            }
            return createAndPersist();
        } catch (Exception firstFailure) {
            reset();
            try {
                return createAndPersist();
            } catch (Exception secondFailure) {
                throw new IllegalStateException("Unable to establish Android device credential.", secondFailure);
            }
        }
    }

    static boolean isCredentialFormatValid(String credential) {
        return credential != null && CREDENTIAL_PATTERN.matcher(credential).matches();
    }

    private String readExisting() throws Exception {
        String encodedCiphertext = preferences.getString(PREF_CIPHERTEXT, "");
        String encodedIv = preferences.getString(PREF_IV, "");
        if (encodedCiphertext == null || encodedCiphertext.isEmpty()
                || encodedIv == null || encodedIv.isEmpty()) {
            return null;
        }

        SecretKey key = getOrCreateKey();
        Cipher cipher = Cipher.getInstance(TRANSFORMATION);
        byte[] iv = Base64.decode(encodedIv, BASE64_FLAGS);
        cipher.init(Cipher.DECRYPT_MODE, key, new GCMParameterSpec(128, iv));
        byte[] plaintext = cipher.doFinal(Base64.decode(encodedCiphertext, BASE64_FLAGS));
        String credential = new String(plaintext, StandardCharsets.UTF_8);
        if (!isCredentialFormatValid(credential)) {
            throw new IllegalStateException("Stored Android device credential is invalid.");
        }
        return credential;
    }

    private String createAndPersist() throws Exception {
        byte[] random = new byte[32];
        secureRandom.nextBytes(random);
        String credential = Base64.encodeToString(random, BASE64_FLAGS);
        if (!isCredentialFormatValid(credential)) {
            throw new IllegalStateException("Generated Android device credential is invalid.");
        }

        SecretKey key = getOrCreateKey();
        Cipher cipher = Cipher.getInstance(TRANSFORMATION);
        cipher.init(Cipher.ENCRYPT_MODE, key);
        byte[] ciphertext = cipher.doFinal(credential.getBytes(StandardCharsets.UTF_8));

        boolean committed = preferences.edit()
                .putString(PREF_CIPHERTEXT, Base64.encodeToString(ciphertext, BASE64_FLAGS))
                .putString(PREF_IV, Base64.encodeToString(cipher.getIV(), BASE64_FLAGS))
                .commit();
        if (!committed) {
            throw new IllegalStateException("Android device credential could not be persisted.");
        }
        return credential;
    }

    private SecretKey getOrCreateKey() throws Exception {
        KeyStore keyStore = KeyStore.getInstance(KEYSTORE);
        keyStore.load(null);
        if (keyStore.containsAlias(KEY_ALIAS)) {
            KeyStore.Entry entry = keyStore.getEntry(KEY_ALIAS, null);
            if (entry instanceof KeyStore.SecretKeyEntry) {
                return ((KeyStore.SecretKeyEntry) entry).getSecretKey();
            }
            throw new IllegalStateException("Android Keystore alias has an unexpected type.");
        }

        KeyGenerator keyGenerator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE);
        keyGenerator.init(new KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT
        )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .setRandomizedEncryptionRequired(true)
                .build());
        return keyGenerator.generateKey();
    }

    private void reset() {
        preferences.edit().clear().commit();
        try {
            KeyStore keyStore = KeyStore.getInstance(KEYSTORE);
            keyStore.load(null);
            if (keyStore.containsAlias(KEY_ALIAS)) {
                keyStore.deleteEntry(KEY_ALIAS);
            }
        } catch (Exception ignored) {
            // A following create attempt still fails closed if the key cannot be rebuilt.
        }
    }
}
