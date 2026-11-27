#!/usr/bin/env python3
"""يُشغَّل في GitHub Actions بعد cap sync: يضيف خدمة المقدمة (Foreground Service) والصلاحيات لتبقى الشغلة في الخلفية."""
import os, re, sys
root = sys.argv[1] if len(sys.argv) > 1 else 'android'
pkg_dir = os.path.join(root, 'app/src/main/java/com/seengeem/assistant')
os.makedirs(pkg_dir, exist_ok=True)
open(os.path.join(pkg_dir, 'MainActivity.java'), 'w', encoding='utf-8').write('''package com.seengeem.assistant;

import android.Manifest;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
  @Override
  public void onCreate(Bundle b) {
    super.onCreate(b);
    try {
      if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
        requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 1);
      }
    } catch (Exception e) { }
    try {
      Intent i = new Intent(this, EngineService.class);
      if (Build.VERSION.SDK_INT >= 26) startForegroundService(i); else startService(i);
    } catch (Exception e) { }
    try {
      PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
      if (Build.VERSION.SDK_INT >= 23 && !pm.isIgnoringBatteryOptimizations(getPackageName())) {
        Intent i = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS);
        i.setData(Uri.parse("package:" + getPackageName()));
        startActivity(i);
      }
    } catch (Exception e) { }
  }
}
''')
open(os.path.join(pkg_dir, 'EngineService.java'), 'w', encoding='utf-8').write('''package com.seengeem.assistant;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.IBinder;
import android.os.PowerManager;

public class EngineService extends Service {
  private PowerManager.WakeLock wl;
  @Override public IBinder onBind(Intent i) { return null; }
  @Override
  public int onStartCommand(Intent intent, int flags, int startId) {
    String ch = "sg_engine";
    if (Build.VERSION.SDK_INT >= 26) {
      NotificationChannel c = new NotificationChannel(ch, "Engine", NotificationManager.IMPORTANCE_LOW);
      ((NotificationManager) getSystemService(NOTIFICATION_SERVICE)).createNotificationChannel(c);
    }
    Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(this, ch) : new Notification.Builder(this);
    b.setContentTitle("Seen Gem").setContentText("Engine running").setSmallIcon(getApplicationInfo().icon).setOngoing(true);
    Intent li = getPackageManager().getLaunchIntentForPackage(getPackageName());
    if (li != null) b.setContentIntent(PendingIntent.getActivity(this, 0, li, PendingIntent.FLAG_IMMUTABLE));
    Notification n = b.build();
    if (Build.VERSION.SDK_INT >= 34) startForeground(1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE); else startForeground(1, n);
    if (wl == null) {
      PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
      wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "sg:engine");
      wl.setReferenceCounted(false);
      wl.acquire();
    }
    return START_STICKY;
  }
  @Override public void onDestroy() { if (wl != null && wl.isHeld()) wl.release(); super.onDestroy(); }
}
''')
mp = os.path.join(root, 'app/src/main/AndroidManifest.xml')
m = open(mp, encoding='utf-8').read()
perms = ''.join('    <uses-permission android:name="android.permission.%s" />\n' % p for p in
  ['FOREGROUND_SERVICE', 'FOREGROUND_SERVICE_SPECIAL_USE', 'POST_NOTIFICATIONS', 'WAKE_LOCK', 'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS', 'RECEIVE_BOOT_COMPLETED'])
for p in re.findall(r'android\.permission\.(\w+)', perms):
    if 'android.permission.%s"' % p in m: perms = perms.replace('    <uses-permission android:name="android.permission.%s" />\n' % p, '')
m = m.replace('</manifest>', perms + '</manifest>', 1)
svc = '''        <service android:name=".EngineService" android:exported="false" android:foregroundServiceType="specialUse">
            <property android:name="android.app.PROPERTY_SPECIAL_USE_FGS_SUBTYPE" android:value="whatsapp_message_sender" />
        </service>
    '''
m = m.replace('</application>', svc + '</application>', 1)
open(mp, 'w', encoding='utf-8').write(m)
print('patched', mp)
