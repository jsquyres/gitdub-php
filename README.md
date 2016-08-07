This is a simple re-spin of Matthias Vallentin's "gitdub", but in a
pure PHP form (the original "gitdub" project is a Ruby / Sinatra
daemon).

This project exists so that you can run a git email notifier in web
hosting environments where you are unable to run additional daemons.
For example, many simple web hosting packages allow arbitrary PHP web
pages, but do not allow running standalone processes (such as a Ruby /
Sinatra process) for more than a short period of time.
