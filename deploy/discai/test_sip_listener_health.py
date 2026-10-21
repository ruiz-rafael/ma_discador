import unittest
from unittest.mock import patch
import sync_sip_trunks as runtime

class ListenerRecoveryTest(unittest.TestCase):
    def test_healthy_listener_does_not_restart(self):
        with patch.object(runtime,'inbound_tls_ready',return_value=True), patch.object(runtime,'run') as run:
            self.assertFalse(runtime.ensure_inbound_listener('127.0.0.1',{}))
            run.assert_not_called()

    def test_missing_listener_recovers_even_without_configuration_change(self):
        with patch.object(runtime,'inbound_tls_ready',side_effect=[False,False,True]), patch.object(runtime,'cli',return_value='0 active channels'), patch.object(runtime,'run') as run, patch.object(runtime.time,'sleep'):
            self.assertTrue(runtime.ensure_inbound_listener('127.0.0.1',{'tenant':{'busy':False}}))
            run.assert_called_once_with(['docker','restart','zyrex-ma-pbx-1'])

    def test_live_reservation_or_call_prevents_restart(self):
        for busy,channels in [(True,'0 active channels'),(False,'2 active channels'),(False,'10 active channels')]:
            with self.subTest(busy=busy), patch.object(runtime,'inbound_tls_ready',return_value=False), patch.object(runtime,'cli',return_value=channels), patch.object(runtime,'run') as run:
                with self.assertRaisesRegex(RuntimeError,'deferred'):
                    runtime.ensure_inbound_listener('127.0.0.1',{'tenant':{'busy':busy}})
                run.assert_not_called()

    def test_failed_restart_cannot_report_ready(self):
        with patch.object(runtime,'inbound_tls_ready',return_value=False), patch.object(runtime,'cli',return_value='0 active channels'), patch.object(runtime,'run') as run, patch.object(runtime.time,'sleep'):
            with self.assertRaisesRegex(RuntimeError,'after PBX restart'):
                runtime.ensure_inbound_listener('127.0.0.1',{})
            self.assertEqual(run.call_count,1)

    def test_refused_connection_is_unhealthy(self):
        with patch.object(runtime.socket,'create_connection',side_effect=ConnectionRefusedError):
            self.assertFalse(runtime.inbound_tls_ready('127.0.0.1'))

if __name__=='__main__':unittest.main()
