# Throwaway sshd target for the integration/e2e test suites.
# Listens only on the compose-published 127.0.0.1:2299 of the host.
FROM alpine:3.21

RUN apk add --no-cache openssh bash coreutils iproute2 \
    && ssh-keygen -A \
    && adduser -D -s /bin/bash wts \
    && echo 'wts:wts-secret' | chpasswd \
    && mkdir -p /home/wts/.ssh \
    && chmod 700 /home/wts/.ssh

# Both committed throwaway public keys may log in as wts.
COPY keys/wts_test_key.pub keys/wts_test_key_pw.pub /tmp/keys/
RUN cat /tmp/keys/wts_test_key.pub /tmp/keys/wts_test_key_pw.pub > /home/wts/.ssh/authorized_keys \
    && chmod 600 /home/wts/.ssh/authorized_keys \
    && chown -R wts:wts /home/wts/.ssh \
    && rm -rf /tmp/keys

RUN { \
        echo 'PasswordAuthentication yes'; \
        echo 'PubkeyAuthentication yes'; \
        echo 'PermitRootLogin no'; \
        echo 'AllowUsers wts'; \
        # The stress harness opens ~100 SSH connections in a burst; the \
        # default MaxStartups (10:30:100) would drop most of the herd. \
        echo 'MaxStartups 256'; \
    } >> /etc/ssh/sshd_config

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D", "-e"]
